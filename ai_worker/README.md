# AI Middleware Worker

**Visão geral**

Este serviço realiza o processamento intensivo de:
- **Text‑to‑Speech (TTS)**
- **Lip‑Sync** (geração de vídeo sincronizado com áudio)
- **Renderização final** (combinação ou re‑encode de vídeo)
- **Geração de avatar estático** (ex.: Stable Diffusion)

Ele expõe uma API FastAPI e utiliza **Redis + RQ** para enfileirar tarefas que são executadas por um worker dedicado.

---

## 📦 Configuração

### 1️⃣ Executar com Docker (recomendado)
```bash
docker-compose up --build
```
O `docker‑compose.yml` cria três contêineres:
- **api** – servidor FastAPI (`uvicorn app.main:app`)
- **redis** – instância Redis usada pelo RQ
- **worker** – worker RQ que processa as tarefas

### 2️⃣ Desenvolvimento local
```bash
# Instalar dependências Python
pip install -r requirements.txt

# Iniciar Redis (pode usar o Docker ou rodar localmente)
redis-server &

# Executar a API (auto‑reload habilitado)
uvicorn app.main:app --reload

# Em outro terminal, iniciar o worker RQ
rq worker
```

---

## 🚀 Endpoints da API

| Método | Rota | Descrição | Exemplo de payload |
|--------|------|-----------|--------------------|
| **GET** | `/` | Verifica a saúde do serviço – retorna `{ "status": "ok", "service": "ai-worker" }` | – |
| **POST** | `/tts` | Enfileira um job de TTS. Retorna `job_id`. | ```json { "text": "Olá, mundo!", "voice": "alloy", "speed": 1.0, "emotion": "natural" } ``` |
| **POST** | `/lipsync` | Enfileira um job de geração de vídeo com lip‑sync. | ```json { "avatar_image": "/tmp/avatar.png", "audio": "/tmp/audio.wav", "emotion": "neutral" } ``` |
| **POST** | `/render` | Enfileira a renderização final (re‑encode ou combinação). | ```json { "avatar_image": "/tmp/avatar.png", "audio": "/tmp/audio.wav", "input_video": null } ``` |
| **POST** | `/avatar` | Enfileira a geração de avatar estático (ex.: Stable Diffusion). | ```json { "prompt": "Um avatar robô futurista" } ``` |
| **GET** | `/jobs/{job_id}` | Consulta o status e o resultado de qualquer job. | – |

### Formato de resposta (para os endpoints POST)
```json
{
  "job_id": "<rq‑job‑uuid>",
  "status": "queued",
  "task": "tts"   // ou "lipsync", "render", "avatar"
}
```

### Endpoint de status do job (`/jobs/{job_id}`)
```json
{
  "job_id": "<rq‑job‑uuid>",
  "status": "finished",   // queued, started, failed, finished
  "result": "<caminho‑para‑arquivo‑gerado>",
  "enqueued_at": "2025-12-12T21:00:00Z",
  "started_at": "2025-12-12T21:00:05Z",
  "ended_at": "2025-12-12T21:00:20Z",
  "exc_info": null
}
```

---

## 🛠️ Extensões e customizações
- Implemente a lógica real nos arquivos `app/services/tts_service.py`, `lipsync_service.py`, `render_service.py` e `avatar_service.py`.
- Os arquivos atuais contêm *stubs*; substitua‑os por chamadas aos seus modelos preferidos (ex.: OpenAI TTS, SadTalker, Stable Diffusion, FFmpeg).
- Todos os contêineres compartilham o diretório `/tmp` para arquivos temporários. Ajuste os caminhos caso monte um volume diferente.

---

**Observação:** Mantenha o `.env` (ou variáveis de ambiente) configurado com as credenciais necessárias para os serviços externos que você integrar.
