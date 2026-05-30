from aiogram.fsm.state import State, StatesGroup

class DraftStates(StatesGroup):

    await_title = State()
    edit_description = State()
    edit_prize = State()
    edit_winners = State()
    edit_nickname = State()
    broadcast_text = State()
    waiting_task_proof = State()

class ParticipationStates(StatesGroup):

    await_phone = State()
    check_nickname = State()

class TaskStates(StatesGroup):

    collecting_proof = State()
