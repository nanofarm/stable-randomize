import time

_active_drafts: dict[int, tuple[str, float]] = {}
_TTL = 3600

def set_active_draft(user_id: int, giveaway_id: str) -> None:
    _active_drafts[user_id] = (giveaway_id, time.monotonic())
    _cleanup()

def get_active_draft(user_id: int) -> str | None:
    v = _active_drafts.get(user_id)
    if not v:
        return None
    gid, ts = v
    if time.monotonic() - ts > _TTL:
        _active_drafts.pop(user_id, None)
        return None
    return gid

def pop_active_draft(user_id: int) -> str | None:
    v = _active_drafts.pop(user_id, None)
    if not v:
        return None
    gid, ts = v
    if time.monotonic() - ts > _TTL:
        return None
    return gid

def _cleanup() -> None:
    if len(_active_drafts) < 500:
        return
    now = time.monotonic()
    expired = [k for k, (_, ts) in _active_drafts.items() if now - ts > _TTL]
    for k in expired:
        _active_drafts.pop(k, None)
