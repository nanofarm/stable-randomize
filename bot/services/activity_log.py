import json
import os
import logging
import threading
from datetime import datetime, timezone

LOG_FILE = os.getenv("ACTIVITY_LOG_FILE", "/app/data/bot_activity.json")
logger = logging.getLogger(__name__)
_lock = threading.Lock()

def _load_logs() -> list[dict]:
    try:
        with open(LOG_FILE, "r", encoding="utf-8") as f:
            return json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        return []

def _save_logs(logs: list[dict]):
    with open(LOG_FILE, "w", encoding="utf-8") as f:
        json.dump(logs, f, ensure_ascii=False, indent=2)

def log_activity(user_id: int, username: str | None, action: str, details: str = "", name: str = ""):
    entry = {
        "ts": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
        "user_id": user_id,
        "username": username or "",
        "name": name,
        "action": action,
        "details": details,
    }
    with _lock:
        logs = _load_logs()
        logs.append(entry)
        if len(logs) > 5000:
            logs = logs[-5000:]
        _save_logs(logs)
    logger.info(f"[ACTIVITY] {action} | user={user_id} @{username} | {details}")

def get_logs(limit: int = 50, action_filter: str | None = None) -> list[dict]:
    logs = _load_logs()
    if action_filter:
        logs = [l for l in logs if l["action"] == action_filter]
    return logs[-limit:]

def get_unique_user_ids() -> set[int]:
    logs = _load_logs()
    return {e["user_id"] for e in logs if e.get("user_id")}

def get_stats() -> dict:
    logs = _load_logs()
    users = {e["user_id"] for e in logs if e.get("action") == "start" and e.get("user_id")}
    channels = len({e["details"] for e in logs if e.get("action") == "channel_connect" and e.get("details")})
    joins = len([e for e in logs if e.get("action") == "join_giveaway"])
    return {
        "total_users": len(users),
        "total_channels": channels,
        "total_joins": joins,
    }
