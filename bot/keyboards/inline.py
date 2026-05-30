from aiogram.types import (
    InlineKeyboardMarkup, InlineKeyboardButton,
    KeyboardButton, ReplyKeyboardMarkup,
    KeyboardButtonRequestChat, ChatAdministratorRights,
    WebAppInfo,
)
from config import config

CHANNEL_ADMIN_RIGHTS = ChatAdministratorRights(
    is_anonymous=False,
    can_manage_chat=True,
    can_delete_messages=False,
    can_manage_video_chats=False,
    can_restrict_members=False,
    can_promote_members=False,
    can_change_info=False,
    can_invite_users=True,
    can_post_messages=True,
    can_edit_messages=True,
    can_post_stories=True,
    can_edit_stories=True,
    can_delete_stories=True,
)

class MainMenu:

    @staticmethod
    def menu() -> InlineKeyboardMarkup:
        return InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="📺 Каналы", callback_data="tab_c")],
            [InlineKeyboardButton(text="❓ Помощь", callback_data="menu_help")],
        ])

    @staticmethod
    def back_to_menu() -> InlineKeyboardMarkup:
        return InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="⬅️ В меню", callback_data="menu_root")]
        ])

class GiveawayKeyboard:

    @staticmethod
    def join_button(giveaway_id: str, channels: list) -> InlineKeyboardMarkup:
        rows = []
        for ch in channels:
            if ch.get("link"):
                rows.append([InlineKeyboardButton(text=f"📺 {ch['title']}", url=ch["link"])])
        webapp_url = f"{config.WEBAPP_URL}?startapp=giveaway_{giveaway_id}"
        rows.append([InlineKeyboardButton(
            text="🎲 Участвовать",
            web_app=WebAppInfo(url=webapp_url)
        )])
        return InlineKeyboardMarkup(inline_keyboard=rows)

    @staticmethod
    def active(gid: str, has_participants: bool = False) -> InlineKeyboardMarkup:
        rows = [
            [InlineKeyboardButton(text="📊 Статистика", callback_data=f"g_stats:{gid}")],
        ]
        if has_participants:
            rows.append([InlineKeyboardButton(text="🎲 Выбрать победителей", callback_data=f"g_draw:{gid}")])
            rows.append([InlineKeyboardButton(text="📢 Рассылка участникам", callback_data=f"g_bcast:{gid}")])
        rows.append([InlineKeyboardButton(text="⬅️ В меню", callback_data="menu_root")])
        return InlineKeyboardMarkup(inline_keyboard=rows)

    @staticmethod
    def finished() -> InlineKeyboardMarkup:
        return InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="⬅️ В меню", callback_data="menu_root")],
        ])

    @staticmethod
    def confirm_launch(gid: str) -> InlineKeyboardMarkup:
        return InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="✅ Да, запустить", callback_data=f"g_launch_ok:{gid}")],
            [InlineKeyboardButton(text="⬅️ Отмена", callback_data=f"g_view:{gid}")],
        ])

    @staticmethod
    def confirm_draw(gid: str) -> InlineKeyboardMarkup:
        return InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="✅ Да, выбрать", callback_data=f"g_draw_ok:{gid}")],
            [InlineKeyboardButton(text="⬅️ Отмена", callback_data=f"g_view:{gid}")],
        ])

class DraftKeyboard:

    @staticmethod
    def card(gid: str) -> InlineKeyboardMarkup:
        return InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="📄 Описание", callback_data=f"d_desc:{gid}"),
             InlineKeyboardButton(text="🎁 Приз", callback_data=f"d_prize:{gid}")],
            [InlineKeyboardButton(text="🏆 Победители", callback_data=f"d_win:{gid}"),
             InlineKeyboardButton(text="👤 Ник-условие", callback_data=f"d_nick:{gid}")],
            [InlineKeyboardButton(text="🖼 Фото", callback_data=f"d_photo:{gid}"),
             InlineKeyboardButton(text="📺 Канал", callback_data=f"pick_ch:{gid}")],
            [InlineKeyboardButton(text="🚀 Запустить", callback_data=f"g_launch:{gid}")],
            [InlineKeyboardButton(text="⬅️ В меню", callback_data="menu_root")],
        ])

    @staticmethod
    def clear_field(gid: str, field: str) -> InlineKeyboardMarkup:
        return InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="🗑 Очистить", callback_data=f"d_clr:{field}:{gid}")],
            [InlineKeyboardButton(text="⬅️ Назад", callback_data=f"g_view:{gid}")],
        ])

    @staticmethod
    def back_to_card(gid: str) -> InlineKeyboardMarkup:
        return InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="⬅️ Назад к розыгрышу", callback_data=f"g_view:{gid}")],
        ])

    @staticmethod
    def winners_count(gid: str) -> InlineKeyboardMarkup:
        return InlineKeyboardMarkup(inline_keyboard=[
            [
                InlineKeyboardButton(text="1", callback_data=f"d_winset:{gid}:1"),
                InlineKeyboardButton(text="3", callback_data=f"d_winset:{gid}:3"),
                InlineKeyboardButton(text="5", callback_data=f"d_winset:{gid}:5"),
                InlineKeyboardButton(text="10", callback_data=f"d_winset:{gid}:10"),
            ],
            [InlineKeyboardButton(text="⬅️ Назад", callback_data=f"g_view:{gid}")],
        ])

class ChannelKeyboard:

    @staticmethod
    def channel_picker() -> ReplyKeyboardMarkup:
        return ReplyKeyboardMarkup(
            keyboard=[
                [
                    KeyboardButton(
                        text="📺 Выбрать канал",
                        request_chat=KeyboardButtonRequestChat(
                            request_id=1,
                            chat_is_channel=True,
                            bot_is_member=False,
                            user_administrator_rights=CHANNEL_ADMIN_RIGHTS,
                            bot_administrator_rights=CHANNEL_ADMIN_RIGHTS,
                        )
                    )
                ]
            ],
            resize_keyboard=True,
            one_time_keyboard=True,
        )
