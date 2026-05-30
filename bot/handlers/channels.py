import logging

from aiogram import Router, F
from aiogram.filters import ChatMemberUpdatedFilter, IS_NOT_MEMBER, ADMINISTRATOR
from aiogram.types import CallbackQuery, Message, ChatMemberUpdated, ReplyKeyboardRemove

from core import ApiClient
from keyboards import MainMenu
from keyboards.inline import InlineKeyboardMarkup, InlineKeyboardButton, ChannelKeyboard
from services.activity_log import log_activity
from services.drafts import set_active_draft, get_active_draft

logger = logging.getLogger(__name__)
router = Router()
api = ApiClient()

@router.callback_query(F.data == "menu_connect")
async def cb_menu_connect(cb: CallbackQuery):
    await cb.message.answer(
        "📺 <b>Подключение канала</b>\n\n"
        "Сделай бота админом в своём канале — и он подключится автоматически.\n\n"
        "Или нажми кнопку ниже и выбери канал из списка:",
        reply_markup=ChannelKeyboard.channel_picker()
    )
    await cb.answer()

@router.message(F.chat_shared)
async def on_chat_shared(msg: Message):
    uid = msg.from_user.id
    chat_id = msg.chat_shared.chat_id

    await msg.answer("⏳", reply_markup=ReplyKeyboardRemove())

    cd = await api.post("/channels/connect", {"owner_id": uid, "chat_id": chat_id})

    if not cd.get("ok"):
        await msg.answer(
            f"❌ {cd.get('message', cd.get('error', 'Ошибка'))}",
            reply_markup=MainMenu.back_to_menu()
        )
        return

    ch = cd.get("channel", {})

    gid = get_active_draft(uid)
    if gid and ch.get("id"):
        att = await api.post("/giveaway/attach-channel", {
            "giveaway_id": gid,
            "channel_id": ch["id"],
            "owner_id": uid
        })
        if att.get("ok"):
            title = ch.get('title', 'Канал')
            await msg.answer(
                f"✅ <b>{title}</b> подключён и добавлен к розыгрышу!",
                reply_markup=MainMenu.back_to_menu()
            )

            creator_id = att.get("creator_id")
            giveaway_title = att.get("giveaway_title")
            if creator_id and int(creator_id) != int(uid):
                try:
                    await msg.bot.send_message(
                        creator_id,
                        f"🔔 <b>Канал привязан!</b>\n\n"
                        f"Админ @{msg.from_user.username or uid} подключил канал "
                        f"<b>{title}</b> к вашему розыгрышу «{giveaway_title}»."
                    )
                except Exception:
                    pass
            return

    full_name = f"{msg.from_user.first_name or ''} {msg.from_user.last_name or ''}".strip()
    log_activity(uid, msg.from_user.username, "channel_connect", ch.get("title", str(chat_id)), name=full_name)

    await msg.answer(
        f"✅ <b>{ch.get('title', 'Канал')}</b> подключён!\n"
        f"👥 {ch.get('member_count', 0)} подписчиков",
        reply_markup=MainMenu.back_to_menu()
    )

@router.my_chat_member(ChatMemberUpdatedFilter(member_status_changed=IS_NOT_MEMBER >> ADMINISTRATOR))
async def on_bot_admin(event: ChatMemberUpdated):
    uid = event.from_user.id

    d = await api.post("/channels/connect", {
        "owner_id": uid,
        "chat_id": event.chat.id
    })

    if not d.get("ok"):
        return

    ch = d.get("channel", {})
    title = ch.get("title") or event.chat.title or "Канал"

    gid = get_active_draft(uid)
    if gid and ch.get("id"):
        att = await api.post("/giveaway/attach-channel", {
            "giveaway_id": gid,
            "channel_id": ch["id"],
            "owner_id": uid
        })
        if att.get("ok"):
            try:
                await event.bot.send_message(
                    uid,
                    f"✅ Канал <b>{title}</b> подключён и привязан к розыгрышу.",
                    reply_markup=MainMenu.back_to_menu()
                )
            except Exception:
                pass

            creator_id = att.get("creator_id")
            giveaway_title = att.get("giveaway_title")
            if creator_id and int(creator_id) != int(uid):
                try:
                    await event.bot.send_message(
                        creator_id,
                        f"🔔 <b>Канал привязан!</b>\n\n"
                        f"Админ @{event.from_user.username or uid} добавил бота в канал "
                        f"<b>{title}</b>, и он был привязан к вашему розыгрышу «{giveaway_title}»."
                    )
                except Exception:
                    pass
            return

    full_name = f"{event.from_user.first_name or ''} {event.from_user.last_name or ''}".strip()
    log_activity(uid, event.from_user.username, "channel_connect", title, name=full_name)

    try:
        await event.bot.send_message(
            uid,
            f"✅ Канал <b>{title}</b> подключён!\n\n"
            "Теперь его можно добавить в розыгрыш через приложение.",
            reply_markup=MainMenu.menu()
        )
    except Exception:
        pass

@router.callback_query(F.data.startswith("pick_ch:"))
async def cb_pick_channel(cb: CallbackQuery):
    gid = cb.data.split(":", 1)[1]
    set_active_draft(cb.from_user.id, gid)
    await cb.message.answer("Выбери канал:", reply_markup=ChannelKeyboard.channel_picker())
    await cb.answer()

@router.callback_query(F.data.startswith("tab_c"))
async def cb_tab_c(cb: CallbackQuery):
    if cb.data == "tab_c":
        await cb.message.edit_text(
            "📺 <b>Каналы</b>",
            reply_markup=InlineKeyboardMarkup(inline_keyboard=[
                [InlineKeyboardButton(text="📋 Мои каналы", callback_data="tab_c:list")],
                [InlineKeyboardButton(text="➕ Добавить канал", callback_data="tab_c:add")],
                [InlineKeyboardButton(text="⬅️ В меню", callback_data="menu_root")],
            ])
        )
    elif cb.data == "tab_c:list":
        r = await api.get(f"/channels?owner_id={cb.from_user.id}")
        channels = r.get("channels", []) if r.get("ok") else []
        if not channels:
            await cb.message.edit_text(
                "📋 Пока ни одного канала не подключено.",
                reply_markup=InlineKeyboardMarkup(inline_keyboard=[
                    [InlineKeyboardButton(text="➕ Добавить канал", callback_data="tab_c:add")],
                    [InlineKeyboardButton(text="⬅️ Назад", callback_data="tab_c")],
                ])
            )
        else:
            lines = [f"• <b>{c['title']}</b> — 👥 {c.get('member_count', 0)}" for c in channels]
            await cb.message.edit_text(
                "📋 <b>Мои каналы</b>\n\n" + "\n".join(lines),
                reply_markup=InlineKeyboardMarkup(inline_keyboard=[
                    [InlineKeyboardButton(text="➕ Добавить ещё", callback_data="tab_c:add")],
                    [InlineKeyboardButton(text="⬅️ Назад", callback_data="tab_c")],
                ])
            )
    elif cb.data == "tab_c:add":
        me = await cb.bot.me()
        add_link = f"https://t.me/{me.username}?startchannel=true&admin=post_messages+edit_messages+invite_users"
        await cb.message.edit_text(
            "📺 <b>Добавить канал</b>\n\n"
            "Сделай бота админом в своём канале:\n\n"
            f"🔗 <a href=\"{add_link}\">Добавить бота в канал</a>",
            reply_markup=InlineKeyboardMarkup(inline_keyboard=[
                [InlineKeyboardButton(text="⬅️ Назад", callback_data="tab_c")],
            ]),
            disable_web_page_preview=True
        )
        await cb.message.answer(
            "Или выбери канал кнопкой:",
            reply_markup=ChannelKeyboard.channel_picker()
        )
    await cb.answer()
