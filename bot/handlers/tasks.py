import logging
from aiogram import Router, F
from aiogram.fsm.context import FSMContext
from aiogram.types import (
    Message, CallbackQuery,
    InlineKeyboardMarkup, InlineKeyboardButton,
)

from core import ApiClient
from core.states import DraftStates

logger = logging.getLogger(__name__)
router = Router()
api = ApiClient()

async def start_task_proof(msg: Message, state: FSMContext, param: str):
    parts = param.rsplit("_", 1)
    if len(parts) != 2:
        await msg.answer("❌ Ссылка повреждена — открой задание заново из розыгрыша")
        return

    public_id, task_id_str = parts[0], parts[1]
    try:
        task_id = int(task_id_str)
    except ValueError:
        await msg.answer("❌ Ссылка повреждена — открой задание заново из розыгрыша")
        return

    r = await api.get(f"/giveaway/{public_id}", {"viewer_id": msg.from_user.id})
    if not r.get("ok"):
        await msg.answer("❌ Розыгрыш не найден или уже завершён")
        return

    g = r["giveaway"]

    if not g.get("is_participant"):
        await msg.answer("❌ Сначала прими участие в розыгрыше, а потом выполняй задание")
        return

    tasks = g.get("tasks") or []
    task = next((t for t in tasks if int(t.get("id", 0)) == task_id), None)
    if not task:
        await msg.answer("❌ Задание не найдено — возможно, организатор его убрал")
        return

    my_submissions = g.get("my_submissions") or {}
    sub_status = my_submissions.get(str(task_id)) or my_submissions.get(task_id)
    if not sub_status:
        submissions = g.get("task_submissions") or []
        my_sub = next(
            (s for s in submissions
             if int(s.get("task_id", 0)) == task_id and int(s.get("user_id", 0)) == msg.from_user.id),
            None,
        )
        sub_status = my_sub.get("status") if my_sub else None

    if sub_status:
        if sub_status == "approved":
            await msg.answer("✅ Это задание уже подтверждено")
        elif sub_status == "pending":
            await msg.answer("⏳ Это задание уже на проверке")
        else:
            await msg.answer("❌ Это задание отклонено — повторная отправка невозможна")
        return

    await state.set_state(DraftStates.waiting_task_proof)
    await state.update_data(
        giveaway_id=public_id,
        giveaway_creator_id=g.get("creator_id"),
        giveaway_title=g.get("title"),
        task_id=task_id,
        task_text=task.get("text"),
        task_price=task.get("price"),
        proof_messages=[],
    )

    kb = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="✅ Отправить на проверку", callback_data=f"tsend:{public_id}:{task_id}")],
        [InlineKeyboardButton(text="❌ Отмена", callback_data="tcancel")],
    ])

    await msg.answer(
        f"📝 <b>Подтверждение задания</b>\n\n"
        f"🎁 Розыгрыш: <b>{g.get('title')}</b>\n"
        f"📋 Задание: {task.get('text')}\n"
        f"💰 Награда: <b>+{task.get('price')} 🎟</b>\n\n"
        f"<b>Что делать:</b>\n"
        f"1. Выполни задание\n"
        f"2. Пришли сюда скриншот или ссылку как доказательство\n"
        f"3. Нажми <b>«Отправить на проверку»</b>\n\n"
        f"Можно прислать несколько сообщений (фото, текст, ссылки).",
        reply_markup=kb,
    )

@router.message(DraftStates.waiting_task_proof)
async def collect_proof(msg: Message, state: FSMContext):
    data = await state.get_data()
    proof = list(data.get("proof_messages") or [])
    proof.append({
        "chat_id": msg.chat.id,
        "message_id": msg.message_id,
    })
    await state.update_data(proof_messages=proof)

    if len(proof) == 1:
        await msg.reply(
            f"✅ Принято ({len(proof)})\n\n"
            f"Можешь добавить ещё, либо нажми <b>«Отправить на проверку»</b> в сообщении выше."
        )

@router.callback_query(F.data.startswith("tsend:"))
async def cb_submit_task(cb: CallbackQuery, state: FSMContext):
    bot = cb.bot
    data = await state.get_data()
    proof = data.get("proof_messages") or []

    if not proof:
        await cb.answer("Сначала пришли скрины или текст подтверждения", show_alert=True)
        return

    parts = cb.data.split(":")
    public_id = parts[1]
    task_id = int(parts[2])
    creator_id = data.get("giveaway_creator_id")
    task_text = data.get("task_text")
    task_price = data.get("task_price")
    giveaway_title = data.get("giveaway_title")

    if not creator_id:
        await cb.answer("Что-то пошло не так — попробуй открыть задание заново", show_alert=True)
        return

    r = await api.post("/giveaway/submit-task", {
        "giveaway_id": public_id,
        "task_id": task_id,
        "user_id": cb.from_user.id,
        "creator_id": creator_id,
    })
    if not r.get("ok"):
        await cb.answer(r.get("error") or "Не удалось отправить — попробуй позже", show_alert=True)
        return

    submission_id = r.get("submission_id")
    if not submission_id:
        await cb.answer("Не удалось отправить — попробуй позже", show_alert=True)
        return

    user_name = cb.from_user.full_name or cb.from_user.username or f"id{cb.from_user.id}"
    user_handle = f"@{cb.from_user.username}" if cb.from_user.username else f"<code>{cb.from_user.id}</code>"

    try:
        await bot.send_message(
            chat_id=creator_id,
            text=(
                f"📥 <b>Новая заявка на задание</b>\n\n"
                f"🎁 Розыгрыш: <b>{giveaway_title}</b>\n"
                f"👤 Участник: <b>{user_name}</b> ({user_handle})\n"
                f"📋 Задание: {task_text}\n"
                f"💰 Награда: <b>+{task_price} 🎟</b>\n\n"
                f"⬇️ Подтверждение от участника:"
            ),
        )
        for p in proof:
            try:
                await bot.copy_message(
                    chat_id=creator_id,
                    from_chat_id=p["chat_id"],
                    message_id=p["message_id"],
                )
            except Exception as e:
                logger.warning(f"Could not copy proof message: {e}")

        kb = InlineKeyboardMarkup(inline_keyboard=[[
            InlineKeyboardButton(text="✅ Принять", callback_data=f"tdec:{submission_id}:1"),
            InlineKeyboardButton(text="❌ Отклонить", callback_data=f"tdec:{submission_id}:0"),
        ]])
        await bot.send_message(
            chat_id=creator_id,
            text=f"⬆️ Прими решение по этой заявке:",
            reply_markup=kb,
        )
    except Exception as e:
        logger.error(f"Failed to forward proof to admin {creator_id}: {e}")
        await cb.answer("Не удалось отправить организатору — попробуй позже", show_alert=True)
        return

    try:
        await cb.message.edit_text(
            "✅ <b>Отправлено на проверку!</b>\n\n"
            "Мы уведомим тебя в этом чате когда организатор примет решение.",
        )
    except Exception:
        await cb.message.answer("✅ Отправлено на проверку!")
    await state.clear()
    await cb.answer("Отправлено")

@router.callback_query(F.data == "tcancel")
async def cb_cancel_task(cb: CallbackQuery, state: FSMContext):
    await state.clear()
    try:
        await cb.message.edit_text("❌ Отменено")
    except Exception:
        await cb.message.answer("❌ Отменено")
    await cb.answer()

@router.callback_query(F.data.startswith("tdec:"))
async def cb_admin_decision(cb: CallbackQuery):
    bot = cb.bot
    parts = cb.data.split(":")
    if len(parts) != 3:
        await cb.answer("Что-то пошло не так", show_alert=True)
        return

    submission_id = int(parts[1])
    approve = parts[2] == "1"

    r = await api.post("/giveaway/task-decision", {
        "submission_id": submission_id,
        "approve": approve,
        "user_id": cb.from_user.id,
        "creator_id": cb.from_user.id,
    })

    if not r.get("ok"):
        await cb.answer(r.get("error") or "Не удалось сохранить решение — попробуй ещё раз", show_alert=True)
        return

    user_id = r.get("user_id")
    tickets_awarded = r.get("tickets_awarded", 0)
    task_text = r.get("task_text", "")
    giveaway_title = r.get("giveaway_title", "")

    try:
        if approve:
            await cb.message.edit_text(
                f"✅ <b>Принято</b>\n\n"
                f"Участнику начислено <b>+{tickets_awarded} 🎟</b>",
                reply_markup=None,
            )
        else:
            await cb.message.edit_text(
                f"❌ <b>Отклонено</b>\n\n"
                f"Билеты не начислены.",
                reply_markup=None,
            )
    except Exception:
        pass

    if user_id:
        try:
            if approve:
                await bot.send_message(
                    chat_id=user_id,
                    text=(
                        f"✅ <b>Задание принято!</b>\n\n"
                        f"🎁 Розыгрыш: <b>{giveaway_title}</b>\n"
                        f"📋 Задание: {task_text}\n"
                        f"💰 Начислено: <b>+{tickets_awarded} 🎟</b>"
                    ),
                )
            else:
                await bot.send_message(
                    chat_id=user_id,
                    text=(
                        f"❌ <b>Задание отклонено</b>\n\n"
                        f"🎁 Розыгрыш: <b>{giveaway_title}</b>\n"
                        f"📋 Задание: {task_text}\n\n"
                        f"Билеты не начислены."
                    ),
                )
        except Exception as e:
            logger.warning(f"Could not notify user {user_id}: {e}")

    await cb.answer("✅ Принято" if approve else "❌ Отклонено")
