// speech_engine.js — Web Speech API TTS/STT engine + Lottie avatar state
// Replaces TruGen AI iframe: handles AI voice output (TTS), candidate voice input (STT),
// and drives the Lottie avatar visual state.

let recognition = null;
let isSpeaking = false;
let isListening = false;
let engineDestroyed = false;
let lastAgentTranscriptIndex = -1;
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

// ─── STT ──────────────────────────────────────────────────────────────────────

function startListening() {
  if (engineDestroyed || isSpeaking || isListening) return;
  if (!recognition) return;

  try {
    recognition.start();
    isListening = true;
  } catch (e) {
    // Ignore 'already started' errors from rapid state transitions
  }
}

function stopListening() {
  if (!isListening || !recognition) return;
  try {
    recognition.stop();
  } catch (e) {}
  isListening = false;
}

function setupSpeechRecognition() {
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRecognition) {
    console.warn('[SpeechEngine] SpeechRecognition not supported in this browser.');
    return null;
  }

  const rec = new SpeechRecognition();
  rec.lang = 'en-US';
  rec.continuous = false;       // fire per-utterance; we restart after each
  rec.interimResults = false;   // final results only for cleaner transcripts

  rec.onresult = (event) => {
    const transcript = Array.from(event.results)
      .map(r => r[0].transcript)
      .join(' ')
      .trim();

    if (!transcript) return;

    // Add to local transcript panel
    if (window.addLocalTranscript) {
      window.addLocalTranscript('USER', transcript);
    }

    // Hand off to the AI conversation engine
    submitCandidateTurn(transcript);
  };

  rec.onend = () => {
    isListening = false;
    // Auto-restart listening if not speaking and engine is alive
    if (!isSpeaking && !engineDestroyed) {
      setTimeout(() => startListening(), 300);
    }
  };

  rec.onerror = (event) => {
    isListening = false;
    if (event.error === 'aborted' || event.error === 'no-speech') {
      // Normal — restart quietly
      if (!isSpeaking && !engineDestroyed) {
        setTimeout(() => startListening(), 500);
      }
      return;
    }
    if (event.error === 'not-allowed') {
      console.error('[SpeechEngine] Microphone permission denied.');
      updateAvatarState('idle');
      return;
    }
    console.warn('[SpeechEngine] STT error:', event.error);
    setTimeout(() => { if (!isSpeaking && !engineDestroyed) startListening(); }, 1000);
  };

  return rec;
}

// ─── AI Turn Submission ───────────────────────────────────────────────────────

let turnInProgress = false;

async function submitCandidateTurn(userText) {
  if (turnInProgress || !window.sessionId) return;
  turnInProgress = true;
  updateAvatarState('thinking');

  try {
    const res = await fetch(
      `api.php?action=conduct&session_id=${encodeURIComponent(window.sessionId)}`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_message: userText }),
      }
    );
    const data = await res.json();

    if (data.speak_text) {
      // Server returned text to speak (new field added to API responses)
      if (window.addLocalTranscript) {
        window.addLocalTranscript('AGENT', data.speak_text);
      }
      speakText(data.speak_text);
    } else if (data.status === 'success') {
      // Fall back: poll transcripts (pollStatus will pick up the agent's next message)
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

// ─── Public API ───────────────────────────────────────────────────────────────

function initSpeechEngine() {
  if (engineDestroyed) return;

  recognition = setupSpeechRecognition();
  startSynthKeepAlive();
  updateAvatarState('idle');

  // Trigger greeting once Web Speech API is ready
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
  updateAvatarState('idle');
}

// Expose globals for interview.js and browser_proctor.js
window.initSpeechEngine = initSpeechEngine;
window.destroySpeechEngine = destroySpeechEngine;
window.speakText = speakText;
window.updateAvatarState = updateAvatarState;
