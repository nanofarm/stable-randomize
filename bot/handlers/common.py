import logging
import hashlib

from aiogram import Router, F
from aiogram.filters import CommandStart, Command
from aiogram.types import CallbackQuery, Message, InlineKeyboardMarkup, InlineKeyboardButton
from aiogram.fsm.context import FSMContext

from core import ApiClient
from keyboards import MainMenu, ChannelKeyboard
from services.activity_log import log_activity

logger = logging.getLogger(__name__)
router = Router()
api = ApiClient()

@router.message(CommandStart())
async def on_start(msg: Message, state: FSMContext):
    await state.clear()

    args = msg.text.split(maxsplit=1)
    if len(args) > 1:
        param = args[1]

        if param == "verify":
            from aiogram.types import ReplyKeyboardMarkup, KeyboardButton
            kb = ReplyKeyboardMarkup(
                keyboard=[[KeyboardButton(text="📱 Поделиться номером", request_contact=True)]],
                resize_keyboard=True,
                one_time_keyboard=True,
            )
            await msg.answer(
                "📱 <b>Подтверждение номера</b>\n\nНажми кнопку ниже 👇",
                reply_markup=kb
            )
            return

        if param.startswith("join_"):
            from .participants import open_public_giveaway
            await open_public_giveaway(msg, param[5:])
            return

        if param.startswith("task_"):
            from .tasks import start_task_proof
            await start_task_proof(msg, state, param[5:])
            return

        if param.startswith("addch_"):
            giveaway_id = param[6:]
            from services.drafts import set_active_draft
            set_active_draft(msg.from_user.id, giveaway_id)
            await msg.answer(
                "📺 <b>Добавление канала к розыгрышу</b>\n\n"
                "Нажми кнопку ниже и выбери свой канал — он автоматически добавится к розыгрышу.",
                reply_markup=InlineKeyboardMarkup(inline_keyboard=[
                    [InlineKeyboardButton(text="📺 Выбрать канал", callback_data=f"pick_ch:{giveaway_id}")]
                ])
            )
            return

    full_name = f"{msg.from_user.first_name or ''} {msg.from_user.last_name or ''}".strip()
    log_activity(msg.from_user.id, msg.from_user.username, "start", name=full_name)

    from keyboards.inline import WebAppInfo
    from config import config

    try:
        await msg.answer(
            '🎰 <b>StableRandom</b> — бот для розыгрышей\n\n'
            '👇 Нажми кнопку ниже чтобы открыть приложение.\n'
            'Там можно создать розыгрыш или посмотреть те, в которых ты участвуешь.',
            reply_markup=InlineKeyboardMarkup(inline_keyboard=[
                [InlineKeyboardButton(
                    text="🚀 Открыть приложение",
                    web_app=WebAppInfo(url=f"{config.WEBAPP_URL}/app?v=2"),
                )],
            ]),
        )
    except Exception as e:
        logger.error(f"/start send failed: {e}")
        await msg.answer("❌ Не удалось загрузить — попробуй ещё раз через пару секунд")

@router.message(Command("menu"))
async def on_menu(msg: Message, state: FSMContext):
    await state.clear()

    from keyboards.inline import WebAppInfo
    from config import config

    await msg.answer(
        "🎰 <b>StableRandom</b>\n\n"
        "👇 Нажми кнопку чтобы открыть приложение:",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(
                text="🚀 Открыть приложение",
                web_app=WebAppInfo(url=f"{config.WEBAPP_URL}/app?v=2"),
            )],
        ]),
    )

@router.message(Command("test"))
async def on_test_post(msg: Message):
    from config import config
    admin_ids = [int(x) for x in config.ADMIN_IDS.split(",") if x.strip().isdigit()]
    if msg.from_user.id not in admin_ids:
        await msg.answer("❌ Только администраторы могут использовать эту команду.")
        return

    args = msg.text.split(maxsplit=1)
    if len(args) < 2:
        await msg.answer(
            "Использование: <code>/test -1001234567890</code>\n\n"
            "Бот должен быть админом канала."
        )
        return

    try:
        channel_id = int(args[1].strip())
    except ValueError:
        await msg.answer("❌ ID канала должен быть числом (например <code>-1001234567890</code>)")
        return
    try:
        sent = await msg.bot.send_message(
            chat_id=channel_id,
            text=(
                '🎁 '
                '<b>StableRandom</b>\n\n'
                'Бот для проведения розыгрышей в Telegram-каналах.\n'
                'Управление — через веб-приложение.'
            ),
            parse_mode="HTML",
        )
        await msg.answer(f"✅ Отправлено в канал <code>{channel_id}</code> (message_id={sent.message_id})")
    except Exception as e:
        logger.error(f"test_post failed: {e}")
        await msg.answer(f"❌ Не удалось отправить в канал. Проверь что бот — админ канала.")

@router.message(Command("check_hash"))
async def on_check_hash(msg: Message):
    await msg.answer("🔍 Проверяю хеши файлов на сервере...")

    r = await api.get("/source-hash")
    if not r.get("ok"):
        await msg.answer("❌ Не удалось получить хеши — попробуй позже")
        return

    hashes = r.get("hashes", [])
    if not hashes:
        await msg.answer("❌ Хеши не найдены")
        return

    lines = ["🔐 <b>SHA-256 хеши файлов на сервере</b>\n"]
    for h in hashes:
        lines.append(f"📄 <b>{h['file']}</b>")
        lines.append(f"<code>{h['sha256']}</code>\n")

    lines.append(
        "💡 <b>Как проверить:</b>\n"
        "1. Скачай исходный код с GitHub\n"
        "2. Выполни <code>sha256sum имя_файла</code>\n"
        "3. Сравни хеши — если совпадают, код на сервере не изменён\n\n"
        "Алгоритм выбора победителей — взвешенный рандом через <code>random_int()</code> "
        "(криптографически безопасный генератор). Каждый розыгрыш подписан HMAC-SHA256."
    )

    await msg.answer("\n".join(lines))

@router.message(Command("help"))
async def on_help(msg: Message):
    from keyboards.inline import WebAppInfo
    from config import config

    await msg.answer(
        "🎰 <b>StableRandom</b> — помощь\n\n"
        "• Чтобы <b>создать розыгрыш</b> — открой приложение\n"
        "• Чтобы <b>участвовать</b> — перейди по ссылке розыгрыша\n"
        "• Чтобы <b>посмотреть свои розыгрыши</b> — открой приложение\n"
        "• /check_hash — проверить что код на сервере совпадает с GitHub\n\n"
        "👇 Жми кнопку:",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(
                text="🚀 Открыть приложение",
                web_app=WebAppInfo(url=f"{config.WEBAPP_URL}/app?v=2"),
            )],
        ]),
    )

@router.callback_query(F.data == "menu_root")
async def cb_menu_root(cb: CallbackQuery, state: FSMContext):
    await state.clear()

    from keyboards.inline import WebAppInfo
    from config import config

    kb = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(
            text="🚀 Открыть приложение",
            web_app=WebAppInfo(url=f"{config.WEBAPP_URL}/app?v=2"),
        )],
    ])
    try:
        await cb.message.edit_text(
            "🎰 <b>StableRandom</b>\n\n"
            "👇 Нажми кнопку чтобы открыть приложение:",
            reply_markup=kb,
        )
    except Exception:
        await cb.message.answer(
            "🎰 <b>StableRandom</b>\n\n"
            "👇 Нажми кнопку чтобы открыть приложение:",
            reply_markup=kb,
        )
    await cb.answer()

@router.callback_query(F.data == "menu_help")
async def cb_menu_help(cb: CallbackQuery):
    from keyboards.inline import WebAppInfo
    from config import config

    await cb.message.edit_text(
        "🎰 <b>StableRandom</b> — помощь\n\n"
        "• Чтобы <b>создать розыгрыш</b> — открой приложение\n"
        "• Чтобы <b>участвовать</b> — перейди по ссылке розыгрыша\n"
        "• Чтобы <b>посмотреть свои розыгрыши</b> — открой приложение\n"
        "• /check_hash — проверить что код на сервере совпадает с GitHub\n\n"
        "👇 Жми кнопку:",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(
                text="🚀 Открыть приложение",
                web_app=WebAppInfo(url=f"{config.WEBAPP_URL}/app?v=2"),
            )],
        ]),
    )
    await cb.answer()

@router.callback_query(F.data == "menu_my")
async def cb_menu_my(cb: CallbackQuery, state: FSMContext):
    await state.clear()

    from keyboards.inline import WebAppInfo
    from config import config

    await cb.message.edit_text(
        "🎰 <b>StableRandom</b>\n\n"
        "👇 Нажми кнопку чтобы открыть приложение:",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(
                text="🚀 Открыть приложение",
                web_app=WebAppInfo(url=f"{config.WEBAPP_URL}/app?v=2"),
            )],
        ]),
    )
    await cb.answer()

