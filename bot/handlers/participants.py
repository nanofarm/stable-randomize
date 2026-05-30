import logging

from aiogram import Router, F
from aiogram.types import CallbackQuery, Message, WebAppInfo
from aiogram.fsm.context import FSMContext

from core import ApiClient
from config import config
from keyboards import MainMenu, GiveawayKeyboard
from keyboards.inline import InlineKeyboardMarkup, InlineKeyboardButton
from services.activity_log import log_activity

logger = logging.getLogger(__name__)
router = Router()
api = ApiClient()

async def open_public_giveaway(msg: Message, payload: str):
    parts = payload.split("_ref_")
    gid = parts[0]
    ref_id = parts[1] if len(parts) > 1 else None
    
    r = await api.get(f"/giveaway/{gid}")
    if not r.get("ok"):
        await msg.answer("❌ Розыгрыш не найден")
        return
    
    g = r["giveaway"]
    
    txt = f"🎰 <b>{g['title']}</b>\n\n"
    if g.get("description"):
        txt += f"{g['description']}\n\n"
    if g.get("prize"):
        txt += f"🎁 Приз: <b>{g['prize']}</b>\n\n"
    
    txt += f"🏆 Победителей: {g.get('winners_count', 1)}\n"
    txt += f"👥 Участников: {g.get('participant_count', 0)}\n"
    
    if g.get("status") == "finished":
        w = ", ".join(x.get("user_name", "?") for x in g.get("winners", []))
        await msg.answer(txt + f"\n🏆 <b>Завершён!</b>\nПобедители: {w}")
        return
    
    kb_rows = []
    for ch in g.get("channels", []):
        if ch.get("link"):
            kb_rows.append([InlineKeyboardButton(text=f"📺 {ch['title']}", url=ch["link"])])
    
    join_callback = f"join:{gid}"
    if ref_id:
        join_callback += f"_ref_{ref_id}"
    
    webapp_url = f"{config.WEBAPP_URL}?startapp=giveaway_{gid}"
    if ref_id:
        webapp_url += f"_ref_{ref_id}"
    
    kb_rows.append([InlineKeyboardButton(
        text="🎲 Участвовать", 
        web_app=WebAppInfo(url=webapp_url)
    )])
    
    await msg.answer(txt, reply_markup=InlineKeyboardMarkup(inline_keyboard=kb_rows))

@router.callback_query(F.data.startswith("join:"))
async def on_join(cb: CallbackQuery):
    payload = cb.data.split(":")[1]
    parts = payload.split("_ref_")
    gid = parts[0]
    ref_id = parts[1] if len(parts) > 1 else None
    
    u = cb.from_user
    name = f"{u.first_name} {u.last_name or ''}".strip()
    
    await cb.answer("⏳ Проверяем...")
    
    join_data = {
        "giveaway_id": gid,
        "user_id": u.id,
        "user_name": name,
        "language_code": getattr(u, 'language_code', None),
        "is_premium": getattr(u, 'is_premium', False),
        "source": "channel_button",
        "username": u.username or None
    }
    
    if ref_id:
        try:
            join_data["referred_by"] = int(ref_id)
        except (ValueError, TypeError):
            pass
    
    d = await api.post("/giveaway/join", join_data)

    original = getattr(cb.message, "html_text", None) or cb.message.text or ""

    if d.get("ok"):
        log_activity(u.id, u.username, "join_giveaway", gid, name=name)
        await cb.message.edit_text(
            original + f"\n\n✅ <b>Вы участвуете!</b>\n👥 Участников: {d.get('participant_count', '?')}",
            reply_markup=InlineKeyboardMarkup(inline_keyboard=[
                [InlineKeyboardButton(text="🎰 Мои розыгрыши", callback_data="menu_my_participated")]
            ])
        )

    elif d.get("error") == "not_subscribed":
        btns = []
        for ch in d.get("missing_channels", []):
            if ch.get("link"):
                btns.append([InlineKeyboardButton(text=f"📺 {ch['title']}", url=ch["link"])])
        btns.append([InlineKeyboardButton(text="🔄 Проверить и участвовать", callback_data=f"join:{gid}")])
        await cb.message.edit_text(
            original + "\n\n❌ <b>Подпишитесь на каналы:</b>",
            reply_markup=InlineKeyboardMarkup(inline_keyboard=btns)
        )

    elif d.get("error") == "Already joined":
        await cb.message.edit_text(original + "\n\n✅ <b>Вы уже участвуете!</b>")

    else:
        await cb.answer(f"❌ {d.get('error', 'Ошибка')}", show_alert=True)

@router.callback_query(F.data == "menu_my_participated")
async def cb_menu_my_participated(cb: CallbackQuery, state: FSMContext):
    await state.clear()
    
    r = await api.get(f"/giveaways?user_id={cb.from_user.id}")
    participated = r.get("participated", []) if r.get("ok") else []
    
    if not participated:
        await cb.message.edit_text(
            "📋 Ты пока не участвуешь ни в одном розыгрыше.",
            reply_markup=MainMenu.back_to_menu()
        )
        await cb.answer()
        return
    
    rows = []
    for g in participated[:20]:
        icon = {"draft": "📝", "active": "▶️", "finished": "🏁"}.get(g.get("status", ""), "•")
        title = (g.get("title", "Без названия") or "Без названия")[:40]
        pid = g.get("public_id") or g.get("id")
        rows.append([InlineKeyboardButton(text=f"{icon} {title}", callback_data=f"g_view:{pid}")])
    
    rows.append([InlineKeyboardButton(text="⬅️ В меню", callback_data="menu_root")])
    
    await cb.message.edit_text(
        f"📋 <b>Розыгрыши, где ты участвуешь ({len(participated)})</b>",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=rows)
    )
    await cb.answer()

@router.message(F.contact)
async def on_contact(msg: Message):
    contact = msg.contact
    
    if not contact or not contact.phone_number or contact.user_id != msg.from_user.id:
        return
    
    phone = contact.phone_number.replace("+", "").replace(" ", "").replace("-", "")
    is_russian = phone.startswith("7") or phone.startswith("8")
    
    await api.post("/phone/verify", {
        "user_id": msg.from_user.id,
        "is_russian": is_russian
    })
    
    if is_russian:
        await msg.answer(
            "✅ <b>Номер подтверждён!</b>\n\n"
            "Вернитесь в розыгрыш и нажмите «Участвовать».",
            reply_markup=MainMenu.back_to_menu()
        )
    else:
        await msg.answer(
            "❌ <b>Участие доступно только с российским номером (+7)</b>",
            reply_markup=MainMenu.back_to_menu()
        )
