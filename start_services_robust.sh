#!/bin/bash
cd /root/ai_worker

# Kill existing
pkill -f uvicorn
pkill -f python3
pkill -f redis-server

# Start Redis
nohup redis-server > redis.log 2>&1 < /dev/null &
echo "Waiting for Redis..."
sleep 5

export OPENAI_API_KEY="${OPENAI_API_KEY}"

# Start Worker
nohup python3 -m app.main_worker > worker.log 2>&1 < /dev/null &

# Start API
nohup uvicorn app.main:app --host 0.0.0.0 --port 8080 > api.log 2>&1 < /dev/null &

echo "Services started."
ps aux | grep -E 'python|uvicorn|redis'
