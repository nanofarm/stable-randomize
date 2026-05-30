import asyncio
import logging

from aiogram import Router, F, Bot
from aiogram.filters import Command
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import Message, CallbackQuery, InlineKeyboardMarkup, InlineKeyboardButton

from config import config
from services.activity_log import get_logs, get_unique_user_ids, get_stats

logger = logging.getLogger(__name__)
router = Router()

ADMIN_IDS: set[int] = set()
_raw = getattr(config, "ADMIN_IDS", "")
if _raw:
    for part in str(_raw).split(","):
        part = part.strip()
        if part.isdigit():
            ADMIN_IDS.add(int(part))

class BroadcastStates(StatesGroup):
    waiting_message = State()
    waiting_confirm = State()

def _admin_menu() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="📊 Статистика", callback_data="adm:stats")],
        [InlineKeyboardButton(text="📋 Логи", callback_data="adm:logs")],
        [InlineKeyboardButton(text="📣 Рассылка", callback_data="adm:broadcast")],
    ])

def _logs_menu() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="Все действия", callback_data="adm:logs:all")],
        [InlineKeyboardButton(text="🚀 /start", callback_data="adm:logs:start")],
        [InlineKeyboardButton(text="📺 Каналы", callback_data="adm:logs:channel")],
        [InlineKeyboardButton(text="🎲 Участия", callback_data="adm:logs:join")],
        [InlineKeyboardButton(text="⬅️ Назад", callback_data="adm:menu")],
    ])

def _format_logs(logs: list[dict]) -> str:
    if not logs:
        return "Логов пока нет."
    lines = []
    for e in reversed(logs[-30:]):
        name = e.get("name", "")
        uname = f" (@{e['username']})" if e.get("username") else ""
        user_label = f"{name}{uname}" if name else (f"@{e['username']}" if e.get("username") else str(e["user_id"]))
        detail = f" — {e['details']}" if e.get("details") else ""
        lines.append(f"<code>{e['ts']}</code> | <b>{e['action']}</b> | {user_label}{detail}")
    return "\n".join(lines)

@router.message(Command("admin"))
async def on_admin(msg: Message, state: FSMContext):
    await state.clear()
    if msg.from_user.id not in ADMIN_IDS:
        return
    await msg.answer("🔐 <b>Админ-панель</b>", reply_markup=_admin_menu())

@router.callback_query(F.data == "adm:menu")
async def cb_admin_menu(cb: CallbackQuery, state: FSMContext):
    if cb.from_user.id not in ADMIN_IDS:
        await cb.answer("Нет доступа", show_alert=True)
        return
    await state.clear()
    await cb.message.edit_text("🔐 <b>Админ-панель</b>", reply_markup=_admin_menu())
    await cb.answer()

@router.callback_query(F.data == "adm:stats")
async def cb_stats(cb: CallbackQuery):
    if cb.from_user.id not in ADMIN_IDS:
        await cb.answer("Нет доступа", show_alert=True)
        return
    s = get_stats()
    await cb.message.edit_text(
        f"📊 <b>Статистика</b>\n\n"
        f"👥 Всего пользователей: <b>{s['total_users']}</b>\n"
        f"📣 Можно написать: <b>{s['total_users']}</b>\n"
        f"📺 Каналов подключено: <b>{s['total_channels']}</b>\n"
        f"🎲 Участий в розыгрышах: <b>{s['total_joins']}</b>",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="🔄 Обновить", callback_data="adm:stats")],
            [InlineKeyboardButton(text="⬅️ Назад", callback_data="adm:menu")],
        ]),
    )
    await cb.answer()

@router.callback_query(F.data == "adm:logs")
async def cb_logs(cb: CallbackQuery):
    if cb.from_user.id not in ADMIN_IDS:
        await cb.answer("Нет доступа", show_alert=True)
        return
    await cb.message.edit_text("📋 <b>Логи</b>\n\nВыбери категорию:", reply_markup=_logs_menu())
    await cb.answer()

@router.callback_query(F.data.startswith("adm:logs:"))
async def cb_logs_filter(cb: CallbackQuery):
    if cb.from_user.id not in ADMIN_IDS:
        await cb.answer("Нет доступа", show_alert=True)
        return
    filt = cb.data.split(":")[-1]
    action_map = {
        "all": None,
        "start": "start",
        "channel": "channel_connect",
        "join": "join_giveaway",
    }
    action_filter = action_map.get(filt)
    logs = get_logs(limit=30, action_filter=action_filter)
    text = _format_logs(logs)
    label = filt.upper() if filt != "all" else "ВСЕ"
    await cb.message.edit_text(
        f"📋 <b>Логи — {label}</b>\n\n{text}",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="🔄 Обновить", callback_data=cb.data)],
            [InlineKeyboardButton(text="⬅️ Назад", callback_data="adm:logs")],
        ]),
    )
    await cb.answer()

@router.callback_query(F.data == "adm:broadcast")
async def cb_broadcast(cb: CallbackQuery, state: FSMContext):
    if cb.from_user.id not in ADMIN_IDS:
        await cb.answer("Нет доступа", show_alert=True)
        return
    user_count = len(get_unique_user_ids())
    await cb.message.edit_text(
        f"📣 <b>Рассылка</b>\n\n"
        f"Получателей: <b>{user_count}</b>\n\n"
        f"Отправь сообщение которое хочешь разослать (текст, фото, видео).\n"
        f"Или нажми Отмена.",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="❌ Отмена", callback_data="adm:menu")],
        ]),
    )
    await state.set_state(BroadcastStates.waiting_message)
    await cb.answer()

@router.message(BroadcastStates.waiting_message)
async def on_broadcast_message(msg: Message, state: FSMContext):
    if msg.from_user.id not in ADMIN_IDS:
        await state.clear()
        return

    user_ids = get_unique_user_ids()
    total = len(user_ids)

    if total == 0:
        await state.clear()
        await msg.answer("❌ Нет пользователей для рассылки.")
        return

    await state.update_data(broadcast_chat_id=msg.chat.id, broadcast_message_id=msg.message_id)
    await state.set_state(BroadcastStates.waiting_confirm)
    await msg.answer(
        f"📣 Отправить это сообщение <b>{total}</b> пользователям?",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="✅ Отправить", callback_data="adm:broadcast:go")],
            [InlineKeyboardButton(text="❌ Отмена", callback_data="adm:menu")],
        ]),
    )

@router.callback_query(F.data == "adm:broadcast:go")
async def cb_broadcast_go(cb: CallbackQuery, state: FSMContext):
    if cb.from_user.id not in ADMIN_IDS:
        await cb.answer("Нет доступа", show_alert=True)
        return

    data = await state.get_data()
    await state.clear()

    chat_id = data.get("broadcast_chat_id")
    message_id = data.get("broadcast_message_id")
    if not chat_id or not message_id:
        await cb.answer("Ошибка: сообщение не найдено", show_alert=True)
        return

    user_ids = get_unique_user_ids()
    total = len(user_ids)

    await cb.message.edit_text(f"📣 Рассылка: 0/{total}...")
    await cb.answer()

    sent = 0
    failed = 0
    bot: Bot = cb.bot

    for uid in user_ids:
        try:
            await bot.copy_message(chat_id=uid, from_chat_id=chat_id, message_id=message_id)
            sent += 1
        except Exception:
            failed += 1

        if (sent + failed) % 25 == 0:
            try:
                await cb.message.edit_text(f"📣 Рассылка: {sent + failed}/{total}...")
            except Exception:
                pass
            await asyncio.sleep(1)

    try:
        await cb.message.edit_text(
            f"✅ <b>Рассылка завершена</b>\n\n"
            f"📨 Отправлено: <b>{sent}</b>\n"
            f"❌ Не доставлено: <b>{failed}</b> (заблокировали бота)",
            reply_markup=InlineKeyboardMarkup(inline_keyboard=[
                [InlineKeyboardButton(text="⬅️ В админку", callback_data="adm:menu")],
            ]),
        )
    except Exception:
        pass
