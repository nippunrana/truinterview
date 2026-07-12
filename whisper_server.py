import os
import tempfile
from fastapi import FastAPI, UploadFile, File, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from faster_whisper import WhisperModel
import uvicorn

app = FastAPI(title="Local Whisper STT Service")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

MODEL_SIZE = "base.en"
model = None

@app.on_event("startup")
def load_model():
    global model
    print(f"Loading Whisper model '{MODEL_SIZE}' on CPU (int8)...")
    model = WhisperModel(MODEL_SIZE, device="cpu", compute_type="int8")
    print("Whisper model loaded successfully.")

@app.post("/transcribe")
async def transcribe(file: UploadFile = File(...)):
    if not model:
        raise HTTPException(status_code=503, detail="Model not loaded yet")

    with tempfile.NamedTemporaryFile(delete=False, suffix=".webm") as temp_file:
        temp_file.write(await file.read())
        temp_path = temp_file.name

    try:
        segments, info = model.transcribe(temp_path, beam_size=5)
        text = " ".join([segment.text for segment in segments]).strip()
        return {"status": "success", "text": text}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))
    finally:
        if os.path.exists(temp_path):
            os.remove(temp_path)

if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=8090)
