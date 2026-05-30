import time
import logging

from aiogram import Router, F
from aiogram.types import Message, CallbackQuery

from core import ApiClient
from keyboards import DraftKeyboard

logger = logging.getLogger(__name__)
router = Router()
api = ApiClient()

_PHOTO_TTL = 600
_pending_photos: dict[int, tuple[str, float]] = {}

def _set_pending_photo(user_id: int, giveaway_id: str) -> None:
    _pending_photos[user_id] = (giveaway_id, time.monotonic() + _PHOTO_TTL)
    if len(_pending_photos) > 500:
        now = time.monotonic()
        expired = [k for k, (_, exp) in _pending_photos.items() if now > exp]
        for k in expired:
            _pending_photos.pop(k, None)

def _pop_pending_photo(user_id: int) -> str | None:
    v = _pending_photos.pop(user_id, None)
    if not v:
        return None
    gid, expires = v
    if time.monotonic() > expires:
        return None
    return gid

@router.callback_query(F.data.startswith("d_photo:"))
async def cb_photo(cb: CallbackQuery):
    gid = cb.data.split(":", 1)[1]
    _set_pending_photo(cb.from_user.id, gid)
    
    await cb.message.answer(
        "🖼 Пришли фото одним сообщением (в этот чат). Оно будет использовано в посте.",
        reply_markup=DraftKeyboard.back_to_card(gid)
    )
    await cb.answer()

@router.message(F.photo)
async def on_photo(msg: Message):
    uid = msg.from_user.id
    gid = _pop_pending_photo(uid)
    
    if not gid:
        return
    
    file_id = msg.photo[-1].file_id
    
    d = await api.post("/giveaway/upload-photo", {
        "giveaway_id": gid,
        "creator_id": uid,
        "file_id": file_id
    })
    
    if not d.get("ok"):
        await msg.answer(f"❌ {d.get('error', 'Ошибка')}")
        return
    
    await msg.answer("✅ Фото сохранено.")
    
    from .giveaways import show_giveaway_card
    await show_giveaway_card(msg, uid, gid)
