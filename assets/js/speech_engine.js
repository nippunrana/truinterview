// speech_engine.js — Web Speech API TTS/STT engine + Lottie avatar state
// Replaces TruGen AI iframe: handles AI voice output (TTS), candidate voice input (STT),
// and drives the Lottie avatar visual state.

let recognition = null;
let isSpeaking = false;
let isListening = false;
let engineDestroyed = false;
let lottieInstance = null;

// Lottie animation URLs for each state (free, CDN-hosted, no account needed)
const LOTTIE_ANIMATIONS = {
  idle:      'https://assets2.lottiefiles.com/packages/lf20_uwR49r.json',  // subtle AI idle pulse
  speaking:  'https://assets2.lottiefiles.com/packages/lf20_qmfs6c3i.json', // sound/voice wave
  listening: 'https://assets9.lottiefiles.com/packages/lf20_ulfrygww.json', // microphone listening
  thinking:  'https://assets4.lottiefiles.com/packages/lf20_qp1q7mct.json', // thinking dots
};

// ─── Avatar State ─────────────────────────────────────────────────────────────

function updateAvatarState(state) {
  const badge = document.getElementById('avatar-state-badge');
  const statusLabel = document.getElementById('avatar-status-label');
  const container = document.getElementById('lottie-avatar-container');

  if (badge) {
    badge.className = 'avatar-state-badge avatar-state-' + state;
  }

  const labels = {
    idle:      '● Connecting...',
    speaking:  '● Speaking',
    listening: '● Listening',
    thinking:  '◎ Thinking',
  };
  if (statusLabel) {
    statusLabel.textContent = labels[state] || '● Ready';
  }

  // Drive CSS class on container for glow transitions
  if (container) {
    container.className = 'lottie-avatar-container avatar-' + state;
  }

  // Switch Lottie animation if available
  if (window.lottie && LOTTIE_ANIMATIONS[state]) {
    const lottieEl = document.getElementById('lottie-player');
    if (lottieEl) {
      if (lottieInstance) {
        lottieInstance.destroy();
      }
      lottieInstance = window.lottie.loadAnimation({
        container: lottieEl,
        renderer: 'svg',
        loop: true,
        autoplay: true,
        path: LOTTIE_ANIMATIONS[state],
      });
    }
  }
}

// ─── TTS ──────────────────────────────────────────────────────────────────────

// Pick best available English voice (prefer Google US English in Chrome)
function getBestVoice() {
  const voices = window.speechSynthesis.getVoices();
  return (
    voices.find(v => v.name === 'Google US English') ||
    voices.find(v => v.lang === 'en-US' && v.localService === false) ||
    voices.find(v => v.lang.startsWith('en') && v.name.toLowerCase().includes('female')) ||
    voices.find(v => v.lang.startsWith('en')) ||
    voices[0] ||
    null
  );
}

function speakText(text) {
  if (!text || engineDestroyed) return;
  if (!window.speechSynthesis) return;

  // Cancel any in-progress speech
  window.speechSynthesis.cancel();
  stopListening();

  const utterance = new SpeechSynthesisUtterance(text);
  utterance.rate = 0.95;
  utterance.pitch = 1.0;
  utterance.volume = 1.0;

  // Assign voice — may need a small delay on first load for voices to populate
  const assignVoice = () => {
    const voice = getBestVoice();
    if (voice) utterance.voice = voice;
  };

  if (window.speechSynthesis.getVoices().length === 0) {
    window.speechSynthesis.addEventListener('voiceschanged', assignVoice, { once: true });
  } else {
    assignVoice();
  }

  utterance.onstart = () => {
    isSpeaking = true;
    updateAvatarState('speaking');
  };

  utterance.onend = () => {
    isSpeaking = false;
    if (!engineDestroyed) {
      updateAvatarState('listening');
      startListening();
    }
  };

  utterance.onerror = (e) => {
    // 'interrupted' is expected when we cancel() — not a real error
    if (e.error === 'interrupted' || e.error === 'canceled') return;
    console.warn('[SpeechEngine] TTS error:', e.error);
    isSpeaking = false;
    if (!engineDestroyed) {
      updateAvatarState('listening');
      startListening();
    }
  };

  isSpeaking = true;
  window.speechSynthesis.speak(utterance);
}

// Chrome bug: speechSynthesis pauses after ~15s on long texts.
// Keep it alive with a no-op resume ping every 10s.
let synthKeepAlive = null;

function startSynthKeepAlive() {
  if (synthKeepAlive) return;
  synthKeepAlive = setInterval(() => {
    if (window.speechSynthesis.speaking) {
      window.speechSynthesis.pause();
      window.speechSynthesis.resume();
    }
  }, 10000);
}

function stopSynthKeepAlive() {
  if (synthKeepAlive) {
    clearInterval(synthKeepAlive);
    synthKeepAlive = null;
  }
}

// --- STT ----------------------------------------------------------------------

let useLocalVAD = true;
let audioContext = null;
let mediaStreamSource = null;
let analyser = null;
let voiceRecorder = null;
let voiceChunks = [];
let isVoiceRecording = false;
let speakDetected = false;
let silenceTimer = null;
let localAudioStream = null;

const VOICE_THRESHOLD = 0.012; // RMS volume speaking threshold
const SILENCE_TIMEOUT = 1800;   // Auto-submit after 1.8s of silence

function startListening() {
  if (engineDestroyed || isSpeaking || isListening) return;

  isListening = true;
  updateAvatarState('listening');
  if (audioContext && audioContext.state === 'suspended') {
    audioContext.resume();
  }
  const sttInput = document.getElementById('stt-input');
  if (sttInput) {
    sttInput.placeholder = 'Speak or type your response...';
  }
}

function stopListening() {
  isListening = false;
  if (voiceRecorder && voiceRecorder.state === 'recording') {
    const prevSpeak = speakDetected;
    speakDetected = false;
    try {
      voiceRecorder.stop();
    } catch (e) {}
    isVoiceRecording = false;
    clearTimeout(silenceTimer);
    silenceTimer = null;
  }
  if (audioContext && audioContext.state === 'running') {
    audioContext.suspend();
  }
}

async function startVADEngine() {
  if (localAudioStream) return;

  const waveformContainer = document.getElementById('stt-waveform-container');
  if (waveformContainer) {
    waveformContainer.style.display = 'flex';
  }

  try {
    localAudioStream = await navigator.mediaDevices.getUserMedia({ audio: true });
    audioContext = new (window.AudioContext || window.webkitAudioContext)();
    mediaStreamSource = audioContext.createMediaStreamSource(localAudioStream);
    analyser = audioContext.createAnalyser();
    analyser.fftSize = 256;
    mediaStreamSource.connect(analyser);

    const bufferLength = analyser.frequencyBinCount;
    const dataArray = new Float32Array(bufferLength);
    const bars = document.querySelectorAll('#stt-waveform-container .bar');

    voiceRecorder = new MediaRecorder(localAudioStream);
    
    voiceRecorder.ondataavailable = (e) => {
      if (e.data.size > 0) {
        voiceChunks.push(e.data);
      }
    };

    voiceRecorder.onstop = async () => {
      if (!speakDetected || voiceChunks.length === 0) {
        voiceChunks = [];
        return;
      }

      const audioBlob = new Blob(voiceChunks, { type: voiceRecorder.mimeType || 'audio/webm' });
      voiceChunks = [];
      speakDetected = false;

      updateAvatarState('thinking');
      const sttInput = document.getElementById('stt-input');
      if (sttInput) {
        sttInput.placeholder = 'Transcribing voice answer...';
      }

      try {
        const formData = new FormData();
        formData.append('audio', audioBlob, 'recording.webm');
        const res = await fetch('api.php?action=transcribe', {
          method: 'POST',
          body: formData
        });
        const data = await res.json();
        
        if (data.status === 'success' && data.text) {
          const text = data.text.trim();
          if (text) {
            if (sttInput) {
              sttInput.value = '';
              sttInput.placeholder = 'Speak or type your response...';
            }
            if (window.addLocalTranscript) {
              window.addLocalTranscript('USER', text);
            }
            submitCandidateTurn(text);
          } else {
            if (sttInput) {
              sttInput.placeholder = 'Speak or type your response...';
            }
            updateAvatarState('listening');
          }
        } else {
          console.error('[SpeechEngine] Local VAD STT error:', data.message);
          if (sttInput) {
            sttInput.placeholder = 'Transcription failed. Please try speaking again.';
          }
          updateAvatarState('listening');
        }
      } catch (err) {
        console.error('[SpeechEngine] VAD fetch error:', err);
        if (sttInput) {
          sttInput.placeholder = 'Network error. Please type your response.';
        }
        updateAvatarState('listening');
      }
    };

    function checkVolume() {
      if (engineDestroyed) return;

      analyser.getFloatTimeDomainData(dataArray);

      let sum = 0;
      for (let i = 0; i < bufferLength; i++) {
        sum += dataArray[i] * dataArray[i];
      }
      const rms = Math.sqrt(sum / bufferLength);

      if (bars.length > 0) {
        const freqData = new Uint8Array(analyser.frequencyBinCount);
        analyser.getByteFrequencyData(freqData);
        for (let i = 0; i < bars.length; i++) {
          const index = Math.floor(i * (freqData.length / bars.length));
          const value = freqData[index];
          const height = Math.max(4, Math.floor((value / 255) * 28));
          bars[i].style.height = height + 'px';
        }
      }

      if (isListening && !isSpeaking) {
        if (rms > VOICE_THRESHOLD) {
          speakDetected = true;
          clearTimeout(silenceTimer);
          silenceTimer = null;

          if (!isVoiceRecording) {
            isVoiceRecording = true;
            voiceChunks = [];
            try {
              voiceRecorder.start();
              updateAvatarState('listening');
              const sttInput = document.getElementById('stt-input');
              if (sttInput) {
                sttInput.placeholder = 'Recording voice...';
              }
              const recordBtn = document.getElementById('stt-mic-btn');
              if (recordBtn) {
                recordBtn.style.background = 'var(--color-danger-bg)';
                recordBtn.style.borderColor = 'var(--color-danger)';
                recordBtn.style.color = 'var(--color-danger)';
                recordBtn.innerHTML = '<span style="display: block; width: 12px; height: 12px; border-radius: 2px; background: var(--color-danger); animation: pulse 1s infinite;"></span>';
                
                if (!document.getElementById('recording-pulse-style')) {
                  const style = document.createElement('style');
                  style.id = 'recording-pulse-style';
                  style.innerHTML = '@keyframes pulse { 0% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.2); opacity: 0.5; } 100% { transform: scale(1); opacity: 1; } }';
                  document.head.appendChild(style);
                }
              }
            } catch (err) {}
          }
        } else {
          if (isVoiceRecording && speakDetected && !silenceTimer) {
            silenceTimer = setTimeout(() => {
              try {
                voiceRecorder.stop();
              } catch (err) {}
              isVoiceRecording = false;
              silenceTimer = null;
              resetRecordButton();
            }, SILENCE_TIMEOUT);
          }
        }
      }

      requestAnimationFrame(checkVolume);
    }

    checkVolume();
  } catch (err) {
    console.error('[SpeechEngine] VAD initialization failed:', err);
    const sttInput = document.getElementById('stt-input');
    if (sttInput) {
      sttInput.placeholder = 'Microphone blocked or unavailable. Type response...';
    }
  }
}

function resetRecordButton() {
  const recordBtn = document.getElementById('stt-mic-btn');
  if (recordBtn) {
    recordBtn.innerHTML = '<svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"></path></svg>';
    recordBtn.style.background = 'var(--color-surface-elevated)';
    recordBtn.style.borderColor = 'var(--color-border)';
    recordBtn.style.color = 'var(--color-text-primary)';
  }
}

// --- AI Turn Submission -------------------------------------------------------

let turnInProgress = false;

async function submitCandidateTurn(userText) {
  const sId = window.sessionId || (typeof sessionId !== 'undefined' ? sessionId : '');
  if (turnInProgress || !sId) return;
  turnInProgress = true;
  updateAvatarState('thinking');

  try {
    const res = await fetch(
      `api.php?action=conduct&session_id=${encodeURIComponent(sId)}`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_message: userText }),
      }
    );
    const data = await res.json();

    if (data.speak_text) {
      if (window.addLocalTranscript) {
        window.addLocalTranscript('AGENT', data.speak_text);
      }
      speakText(data.speak_text);
    } else if (data.status === 'success') {
      updateAvatarState('listening');
      startListening();
    }
  } catch (e) {
    console.error('[SpeechEngine] Error submitting turn:', e);
    updateAvatarState('listening');
    startListening();
  } finally {
    turnInProgress = false;
  }
}

// --- Public API ---------------------------------------------------------------

function initSpeechEngine() {
  if (engineDestroyed) return;

  startSynthKeepAlive();
  updateAvatarState('idle');
  
  startVADEngine().then(() => {
    startListening();
  });

  setTimeout(() => {
    if (window.triggerGreetingOnce) {
      window.triggerGreetingOnce('local_speech_session');
    }
  }, 1000);
}

function destroySpeechEngine() {
  engineDestroyed = true;
  stopListening();
  stopSynthKeepAlive();
  if (window.speechSynthesis) window.speechSynthesis.cancel();
  if (lottieInstance) {
    lottieInstance.destroy();
    lottieInstance = null;
  }
  useLocalVAD = false;
  if (localAudioStream) {
    localAudioStream.getTracks().forEach(track => track.stop());
    localAudioStream = null;
  }
  updateAvatarState('idle');
}

function initFallbackInput() {
  const sttInput = document.getElementById('stt-input');
  const sendBtn = document.getElementById('stt-send-btn');
  const recordBtn = document.getElementById('stt-mic-btn');

  if (sttInput && sendBtn) {
    const sendResponse = () => {
      const text = sttInput.value.trim();
      if (!text || turnInProgress) return;
      
      stopListening();
      
      if (window.addLocalTranscript) {
        window.addLocalTranscript('USER', text);
      }
      
      sttInput.value = '';
      sttInput.placeholder = 'Speak or type your response...';
      submitCandidateTurn(text);
    };

    sendBtn.addEventListener('click', sendResponse);
    sttInput.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') {
        sendResponse();
      }
    });
  }

  if (recordBtn && sttInput) {
    recordBtn.addEventListener('click', async () => {
      if (voiceRecorder && voiceRecorder.state === 'recording') {
        speakDetected = true;
        clearTimeout(silenceTimer);
        silenceTimer = null;
        try {
          voiceRecorder.stop();
        } catch (e) {}
        isVoiceRecording = false;
        resetRecordButton();
      }
    });
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initFallbackInput);
} else {
  initFallbackInput();
}

// Expose globals for interview.js and browser_proctor.js
window.initSpeechEngine = initSpeechEngine;
window.destroySpeechEngine = destroySpeechEngine;
window.speakText = speakText;
window.updateAvatarState = updateAvatarState;
