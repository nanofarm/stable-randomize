import logging
from datetime import datetime

from aiogram import Router, F
from aiogram.types import CallbackQuery, Message
from aiogram.fsm.context import FSMContext

from core import ApiClient, DraftStates
from keyboards import MainMenu, DraftKeyboard, GiveawayKeyboard
from keyboards.inline import InlineKeyboardMarkup, InlineKeyboardButton

from services.drafts import set_active_draft

logger = logging.getLogger(__name__)
router = Router()
api = ApiClient()

@router.callback_query(F.data == "menu_new")
async def cb_menu_new(cb: CallbackQuery, state: FSMContext):
    await state.clear()
    await state.set_state(DraftStates.await_title)
    await cb.message.edit_text(
        "📝 <b>Новый розыгрыш</b>\n\n"
        "Введи <b>название</b> (до 100 символов).\n"
        "После этого откроется карточка — остальные поля заполняются кнопками.",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="⬅️ Отмена", callback_data="menu_root")]
        ])
    )
    await cb.answer()

@router.message(DraftStates.await_title, F.text)
async def draft_create(msg: Message, state: FSMContext):
    title = (msg.text or "").strip()
    if not title or len(title) > 100:
        await msg.answer("⚠️ Название должно быть от 1 до 100 символов. Попробуй ещё раз:")
        return
    
    u = msg.from_user
    payload = {
        "title": title,
        "winners_count": 1,
        "creator_id": u.id,
        "creator_name": f"{u.first_name} {u.last_name or ''}".strip() or u.username or f"id{u.id}",
    }
    
    r = await api.post("/giveaway/", payload)
    if not r.get("ok"):
        await state.clear()
        await msg.answer(f"❌ Ошибка: {r.get('error', 'unknown')}", reply_markup=MainMenu.back_to_menu())
        return
    
    gid = r["giveaway"].get("public_id") or r["giveaway"].get("id")
    if not gid:
        await state.clear()
        await msg.answer("❌ Ошибка: сервер не вернул id", reply_markup=MainMenu.back_to_menu())
        return
    
    await state.clear()
    set_active_draft(u.id, gid)
    await show_giveaway_card(msg, u.id, gid)

async def show_giveaway_card(
    msg_or_cb: Message | CallbackQuery,
    uid: int,
    gid: str,
    edit: bool = False
) -> None:
    r = await api.get(f"/giveaway/{gid}")
    if not r.get("ok"):
        text = "❌ Розыгрыш не найден"
        kb = MainMenu.back_to_menu()
        if isinstance(msg_or_cb, CallbackQuery):
            await msg_or_cb.message.edit_text(text, reply_markup=kb)
        else:
            await msg_or_cb.answer(text, reply_markup=kb)
        return
    
    g = r["giveaway"]
    if int(g.get("creator_id", 0)) != uid:
        text = "❌ Это не твой розыгрыш"
        kb = MainMenu.back_to_menu()
        if isinstance(msg_or_cb, CallbackQuery):
            await msg_or_cb.message.edit_text(text, reply_markup=kb)
        else:
            await msg_or_cb.answer(text, reply_markup=kb)
        return
    
    set_active_draft(uid, gid)
    status = g.get("status", "draft")
    
    def yn(v):
        return "✅" if v else "⬜️"
    
    has_photo = bool(g.get("photo_path") or g.get("photo_file_id"))
    channels = g.get("channels", [])
    chan_n = len(channels)
    desc = g.get("description")
    prize = g.get("prize")
    nick = g.get("nickname_condition")
    winners = int(g.get("winners_count", 1))
    
    text = f"📝 <b>{g['title']}</b>\n\n"
    text += "Заполни поля кнопками ниже:\n\n"
    text += f"{yn(desc)} Описание" + (f": {desc[:40]}…\n" if desc and len(desc) > 40 else (f": {desc}\n" if desc else "\n"))
    text += f"{yn(prize)} Приз" + (f": {prize}\n" if prize else "\n")
    text += f"🏆 Победителей: <b>{winners}</b>\n"
    text += f"{yn(nick)} Ник для доп.билетов" + (f": {nick}\n" if nick else "\n")
    text += f"📺 Каналов подключено: <b>{chan_n}</b>\n"
    text += f"{yn(has_photo)} Фото\n"
    text += f"\n📊 Статус: <b>{status}</b>\n🆔 <code>{gid}</code>"
    
    if status == "draft":
        kb = DraftKeyboard.card(gid)
    elif status == "active":
        has_parts = g.get("participant_count", 0) > 0
        kb = GiveawayKeyboard.active(gid, has_parts)
    else:
        winners_list = g.get("winners", [])
        if winners_list:
            text += "\n\n🏆 Победители:\n" + "\n".join(
                f"  {i+1}. {w.get('user_name', '?')}" for i, w in enumerate(winners_list)
            )
        kb = GiveawayKeyboard.finished()
    
    message = msg_or_cb.message if isinstance(msg_or_cb, CallbackQuery) else msg_or_cb
    
    if edit and isinstance(msg_or_cb, CallbackQuery):
        try:
            await message.edit_text(text, reply_markup=kb)
            return
        except Exception:
            pass
    
    await message.answer(text, reply_markup=kb)

@router.callback_query(F.data.startswith("g_view:"))
async def cb_giveaway_view(cb: CallbackQuery, state: FSMContext):
    await state.clear()
    gid = cb.data.split(":", 1)[1]
    await show_giveaway_card(cb, cb.from_user.id, gid, edit=True)
    await cb.answer()

@router.callback_query(F.data.startswith("d_desc:"))
async def cb_edit_desc(cb: CallbackQuery, state: FSMContext):
    gid = cb.data.split(":", 1)[1]
    await state.set_state(DraftStates.edit_description)
    await state.update_data(gid=gid)
    await cb.message.edit_text(
        "📄 Пришли <b>описание</b> (до 500 символов) или нажми «Очистить»:",
        reply_markup=DraftKeyboard.clear_field(gid, "description")
    )
    await cb.answer()

@router.message(DraftStates.edit_description, F.text)
async def on_edit_desc(msg: Message, state: FSMContext):
    data = await state.get_data()
    gid = data.get("gid")
    desc = (msg.text or "").strip()
    
    if len(desc) > 500:
        await msg.answer("⚠️ До 500 символов.")
        return
    
    await api.post("/giveaway/update-draft", {
        "giveaway_id": gid,
        "creator_id": msg.from_user.id,
        "description": desc
    })
    await state.clear()
    await show_giveaway_card(msg, msg.from_user.id, gid)

@router.callback_query(F.data.startswith("d_prize:"))
async def cb_edit_prize(cb: CallbackQuery, state: FSMContext):
    gid = cb.data.split(":", 1)[1]
    await state.set_state(DraftStates.edit_prize)
    await state.update_data(gid=gid)
    await cb.message.edit_text(
        "🎁 Что за приз? (до 200 символов)",
        reply_markup=DraftKeyboard.clear_field(gid, "prize")
    )
    await cb.answer()

@router.message(DraftStates.edit_prize, F.text)
async def on_edit_prize(msg: Message, state: FSMContext):
    data = await state.get_data()
    gid = data.get("gid")
    prize = (msg.text or "").strip()
    
    if len(prize) > 200:
        await msg.answer("⚠️ До 200 символов.")
        return
    
    await api.post("/giveaway/update-draft", {
        "giveaway_id": gid,
        "creator_id": msg.from_user.id,
        "prize": prize
    })
    await state.clear()
    await show_giveaway_card(msg, msg.from_user.id, gid)

@router.callback_query(F.data.startswith("d_nick:"))
async def cb_edit_nick(cb: CallbackQuery, state: FSMContext):
    gid = cb.data.split(":", 1)[1]
    await state.set_state(DraftStates.edit_nickname)
    await state.update_data(gid=gid)
    await cb.message.edit_text(
        "👤 <b>Ник для дополнительных билетов</b>\n\n"
        "Напиши текст, который должен быть в username участника (например: <code>randomize</code>). "
        "Участники с таким фрагментом получат х10 билетов.",
        reply_markup=DraftKeyboard.clear_field(gid, "nickname_condition")
    )
    await cb.answer()

@router.message(DraftStates.edit_nickname, F.text)
async def on_edit_nick(msg: Message, state: FSMContext):
    data = await state.get_data()
    gid = data.get("gid")
    nick = (msg.text or "").strip().lstrip("@")
    
    if len(nick) > 50:
        await msg.answer("⚠️ До 50 символов.")
        return
    
    await api.post("/giveaway/update-draft", {
        "giveaway_id": gid,
        "creator_id": msg.from_user.id,
        "nickname_condition": nick
    })
    await state.clear()
    await show_giveaway_card(msg, msg.from_user.id, gid)

@router.callback_query(F.data.startswith("d_win:"))
async def cb_edit_winners(cb: CallbackQuery, state: FSMContext):
    gid = cb.data.split(":", 1)[1]
    await state.set_state(DraftStates.edit_winners)
    await state.update_data(gid=gid)
    await cb.message.edit_text(
        "🏆 Сколько победителей? Выбери или введи число 1-50:",
        reply_markup=DraftKeyboard.winners_count(gid)
    )
    await cb.answer()

@router.callback_query(F.data.startswith("d_winset:"))
async def cb_winset(cb: CallbackQuery, state: FSMContext):
    _, gid, n = cb.data.split(":")
    await api.post("/giveaway/update-draft", {
        "giveaway_id": gid,
        "creator_id": cb.from_user.id,
        "winners_count": int(n)
    })
    await state.clear()
    await show_giveaway_card(cb.message, cb.from_user.id, gid)
    await cb.answer()

@router.message(DraftStates.edit_winners, F.text.regexp(r"^\d+$"))
async def on_edit_winners(msg: Message, state: FSMContext):
    n = int(msg.text)
    if n < 1 or n > 50:
        await msg.answer("⚠️ От 1 до 50.")
        return
    
    data = await state.get_data()
    gid = data.get("gid")
    await api.post("/giveaway/update-draft", {
        "giveaway_id": gid,
        "creator_id": msg.from_user.id,
        "winners_count": n
    })
    await state.clear()
    await show_giveaway_card(msg, msg.from_user.id, gid)

@router.callback_query(F.data.startswith("d_clr:"))
async def cb_clear_field(cb: CallbackQuery, state: FSMContext):
    _, field, gid = cb.data.split(":", 2)
    await api.post("/giveaway/update-draft", {
        "giveaway_id": gid,
        "creator_id": cb.from_user.id,
        field: None
    })
    await state.clear()
    await show_giveaway_card(cb.message, cb.from_user.id, gid, edit=True)
    await cb.answer("Очищено")

@router.callback_query(F.data.startswith("g_stats:"))
async def cb_giveaway_stats(cb: CallbackQuery, state: FSMContext):
    await state.clear()
    gid = cb.data.split(":", 1)[1]

    r = await api.get(f"/giveaway/{gid}")
    if not r.get("ok"):
        await cb.answer("❌ Не найден", show_alert=True)
        return
    g = r["giveaway"]
    if int(g.get("creator_id", 0)) != cb.from_user.id:
        await cb.answer("Не твой розыгрыш", show_alert=True)
        return

    status = g.get("status", "draft")
    participants = int(g.get("participant_count", 0))
    channels = g.get("channels", [])
    winners = g.get("winners", [])

    text = f"📊 <b>Статистика</b>\n<b>{g.get('title', '—')}</b>\n\n"
    text += f"Статус: <b>{status}</b>\n"
    text += f"👥 Участников: <b>{participants}</b>\n"
    text += f"🏆 Победителей запланировано: <b>{g.get('winners_count', 1)}</b>\n"
    text += f"📺 Каналов: <b>{len(channels)}</b>\n"
    if channels:
        total_subs = sum(int(c.get("member_count", 0)) for c in channels)
        text += f"   ↳ суммарная аудитория: <b>{total_subs}</b>\n"
        if total_subs > 0:
            text += f"   ↳ конверсия в участники: <b>{participants * 100 / total_subs:.2f}%</b>\n"
    end_date = g.get("end_date")
    if end_date:
        try:
            dt = datetime.fromisoformat(end_date.replace("Z", "+00:00"))
            text += f"⏰ До: <b>{dt.strftime('%d.%m.%Y %H:%M')}</b>\n"
        except Exception:
            pass
    if winners:
        text += "\n🏆 Победители:\n"
        for i, w in enumerate(winners, 1):
            text += f"  {i}. {w.get('user_name', '?')}\n"

    await cb.message.edit_text(text, reply_markup=InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="⬅️ К розыгрышу", callback_data=f"g_view:{gid}")],
        [InlineKeyboardButton(text="⬅️ В меню", callback_data="menu_root")],
    ]))
    await cb.answer()
