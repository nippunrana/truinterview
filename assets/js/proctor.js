// assets/js/proctor.js - Client-Side Proctoring Engine using MediaPipe Face Landmarker

let FilesetResolver = null;
let FaceLandmarker = null;
let faceLandmarker = null;
let webcamStream = null;
let detectionInterval = null;
let isInitialized = false;

// Cooldown tracker: alert_type => timestamp when cooldown ends
const alertCooldowns = {};

// Timer trackers
let noFaceStart = null;
let gazeAwayStart = null;

let statusCallback = null;
let landmarksCallback = null;
let activeSessionId = null;

// Hidden video/canvas for tracking
let trackingVideo = null;
let trackingCanvas = null;

export function getWebcamStream() {
    return webcamStream;
}

export async function initProctor(sessionId, onStatusChange, onLandmarks) {
    if (isInitialized) {
        console.warn("Proctoring is already initialized.");
        return;
    }
    
    activeSessionId = sessionId;
    statusCallback = onStatusChange;
    landmarksCallback = onLandmarks;
    
    if (statusCallback) {
        statusCallback('connecting');
    }
    
    try {
        console.log("Loading MediaPipe Face Landmarker library dynamically...");
        const mpVision = await import("https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.8/vision_bundle.mjs");
        FilesetResolver = mpVision.FilesetResolver;
        FaceLandmarker = mpVision.FaceLandmarker;

        console.log("Initializing MediaPipe Face Landmarker...");
        const vision = await FilesetResolver.forVisionTasks(
            "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.8/wasm"
        );
        
        faceLandmarker = await FaceLandmarker.createFromOptions(vision, {
            baseOptions: {
                modelAssetPath: "https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task",
                delegate: "GPU"
            },
            outputFaceBlendshapes: true,
            runningMode: "VIDEO",
            numFaces: 2
        });
        
        console.log("MediaPipe initialized successfully. Accessing webcam...");
        
        webcamStream = await navigator.mediaDevices.getUserMedia({
            video: {
                width: { ideal: 640 },
                height: { ideal: 480 },
                facingMode: "user"
            },
            audio: true
        });
        
        // Create off-screen video element
        trackingVideo = document.createElement('video');
        trackingVideo.srcObject = webcamStream;
        trackingVideo.autoplay = true;
        trackingVideo.playsInline = true;
        trackingVideo.muted = true;
        
        // Hide off-screen video safely to ensure browsers continue to play and render it
        trackingVideo.style.position = 'absolute';
        trackingVideo.style.width = '1px';
        trackingVideo.style.height = '1px';
        trackingVideo.style.opacity = '0';
        trackingVideo.style.pointerEvents = 'none';
        document.body.appendChild(trackingVideo);
        
        // Create off-screen canvas element for snapshot captures
        trackingCanvas = document.createElement('canvas');
        trackingCanvas.width = 640;
        trackingCanvas.height = 480;
        trackingCanvas.style.display = 'none';
        document.body.appendChild(trackingCanvas);
        
        // Start detection loop (1 FPS to minimize CPU overhead)
        detectionInterval = setInterval(() => {
            if (trackingVideo.readyState >= 2) { // HAVE_CURRENT_DATA or better
                try {
                    const timestamp = performance.now();
                    const result = faceLandmarker.detectForVideo(trackingVideo, timestamp);
                    processProctorResult(result);
                } catch (err) {
                    console.error("Proctor loop detection error:", err);
                }
            }
        }, 1000);
        
        isInitialized = true;
        if (statusCallback) {
            statusCallback('ok');
        }
        
    } catch (err) {
        console.error("Failed to initialize webcam proctoring:", err);
        if (statusCallback) {
            statusCallback('error');
        }
    }
}

/**
 * Clean up webcam stream, remove elements, and stop timers.
 */
export function destroyProctor() {
    if (detectionInterval) {
        clearInterval(detectionInterval);
        detectionInterval = null;
    }
    
    if (webcamStream) {
        const tracks = webcamStream.getTracks();
        tracks.forEach(track => {
            track.stop();
        });
        webcamStream = null;
    }
    
    if (trackingVideo) {
        trackingVideo.srcObject = null;
        if (typeof trackingVideo.load === 'function') {
            trackingVideo.load();
        }
        trackingVideo.remove();
        trackingVideo = null;
    }
    
    if (trackingCanvas) {
        trackingCanvas.remove();
        trackingCanvas = null;
    }
    
    if (faceLandmarker) {
        try {
            faceLandmarker.close();
        } catch (e) {
            console.error("Error closing faceLandmarker:", e);
        }
        faceLandmarker = null;
    }
    isInitialized = false;
    activeSessionId = null;
    statusCallback = null;
    landmarksCallback = null;
    noFaceStart = null;
    gazeAwayStart = null;
    
    console.log("Proctoring system shut down.");
}

/**
 * Horizontal gaze tracking and head pose estimation logic.
 */
function estimateGaze(landmarks) {
    const leftOuter = landmarks[33];
    const leftInner = landmarks[133];
    const leftIris = landmarks[468];

    const rightOuter = landmarks[263];
    const rightInner = landmarks[362];
    const rightIris = landmarks[473];

    if (!leftOuter || !leftInner || !leftIris || !rightOuter || !rightInner || !rightIris) {
        return 0.5;
    }

    const leftWidth = Math.abs(leftInner.x - leftOuter.x);
    const rightWidth = Math.abs(rightInner.x - rightOuter.x);

    if (leftWidth === 0 || rightWidth === 0) return 0.5;

    const leftMin = Math.min(leftOuter.x, leftInner.x);
    const rightMin = Math.min(rightOuter.x, rightInner.x);

    const leftRatio = (leftIris.x - leftMin) / leftWidth;
    const rightRatio = (rightIris.x - rightMin) / rightWidth;

    return (leftRatio + rightRatio) / 2;
}

/**
 * Local rules engine. Evaluates anomalies and determines alert state.
 */
function processProctorResult(result) {
    const faces = result.faceLandmarks || [];
    
    if (landmarksCallback) {
        try {
            landmarksCallback(faces);
        } catch (err) {
            console.error("Error in proctor landmarks callback:", err);
        }
    }

    const faceCount = faces.length;
    const now = Date.now();
    let currentStatus = 'ok';
    
    // 1. Multiple faces check (immediate critical alert)
    if (faceCount > 1) {
        currentStatus = 'critical';
        noFaceStart = null;
        gazeAwayStart = null;
        
        triggerAlert('multiple_faces', 'critical', { face_count: faceCount });
    }
    // 2. No face check (warn at 10s, critical at 30s)
    else if (faceCount === 0) {
        if (!noFaceStart) {
            noFaceStart = now;
        }
        const absentDuration = (now - noFaceStart) / 1000;
        
        if (absentDuration >= 30) {
            currentStatus = 'critical';
            triggerAlert('no_face', 'critical', { absent_seconds: Math.round(absentDuration) });
        } else if (absentDuration >= 10) {
            currentStatus = 'warning';
            triggerAlert('no_face', 'warning', { absent_seconds: Math.round(absentDuration) });
        } else {
            // Under 10 seconds absent, show warning status but don't fire backend alert yet
            currentStatus = 'warning';
        }
        gazeAwayStart = null;
    }
    // 3. Single face path (estimate gaze direction and head turn yaw/pitch)
    else {
        noFaceStart = null;
        const landmarks = faces[0];
        
        const gazeRatio = estimateGaze(landmarks);
        
        // Head pose yaw & pitch estimation using facial references:
        // Nose (4), Cheek Left (234), Cheek Right (454), Forehead (10), Chin (152)
        const nose = landmarks[4];
        const cheekLeft = landmarks[234];
        const cheekRight = landmarks[454];
        const forehead = landmarks[10];
        const chin = landmarks[152];
        
        let yawRatio = 0.5;
        let pitchRatio = 0.5;
        
        if (nose && cheekLeft && cheekRight) {
            const width = Math.abs(cheekRight.x - cheekLeft.x);
            if (width > 0) {
                const minX = Math.min(cheekLeft.x, cheekRight.x);
                yawRatio = (nose.x - minX) / width;
            }
        }
        
        if (nose && forehead && chin) {
            const height = Math.abs(chin.y - forehead.y);
            if (height > 0) {
                const minY = Math.min(forehead.y, chin.y);
                pitchRatio = (nose.y - minY) / height;
            }
        }
        
        const isGazeAway = (gazeRatio < 0.25 || gazeRatio > 0.75);
        const isHeadTurned = (yawRatio < 0.3 || yawRatio > 0.7 || pitchRatio < 0.35 || pitchRatio > 0.65);
        
        if (isGazeAway || isHeadTurned) {
            if (!gazeAwayStart) {
                gazeAwayStart = now;
            }
            const gazeAwayDuration = (now - gazeAwayStart) / 1000;
            
            if (gazeAwayDuration >= 8) {
                currentStatus = 'warning';
                triggerAlert('gaze_away', 'warning', { 
                    gaze_ratio: Math.round(gazeRatio * 100) / 100, 
                    yaw_ratio: Math.round(yawRatio * 100) / 100,
                    pitch_ratio: Math.round(pitchRatio * 100) / 100,
                    gaze_away_seconds: Math.round(gazeAwayDuration)
                });
            } else {
                // In threshold build-up, set status as warning
                currentStatus = 'warning';
            }
        } else {
            gazeAwayStart = null;
        }
    }
    
    if (statusCallback) {
        statusCallback(currentStatus);
    }
}

/**
 * Captures snapshot canvas frame and POSTs payload alert to backend.
 */
async function triggerAlert(alertType, severity, clientDetails) {
    const now = Date.now();
    
    if (alertCooldowns[alertType] && now < alertCooldowns[alertType]) {
        return;
    }
    
    alertCooldowns[alertType] = now + 60000; // Set 60 seconds cooldown for this alert type
    
    console.warn(`[Proctor Alert] Anomaly detected: ${alertType} (${severity})`, clientDetails);
    
    let snapshot = '';
    if (trackingVideo && trackingCanvas) {
        try {
            const ctx = trackingCanvas.getContext('2d');
            ctx.drawImage(trackingVideo, 0, 0, trackingCanvas.width, trackingCanvas.height);
            snapshot = trackingCanvas.toDataURL('image/jpeg', 0.8);
        } catch (err) {
            console.error("Failed to capture webcam snapshot frame:", err);
        }
    }
    
    try {
        const response = await fetch('api.php?action=proctor_alert', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                session_id: activeSessionId,
                alert_type: alertType,
                severity: severity,
                client_details: clientDetails,
                snapshot: snapshot
            })
        });
        
        const data = await response.json();
        console.log(`[Proctor Alert Response]`, data);
    } catch (err) {
        console.error("Failed to send proctor alert details to api:", err);
    }
}
