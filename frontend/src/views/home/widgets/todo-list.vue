<script setup>
import { ref, computed } from 'vue'
import { useAppStore } from '../../../stores/app'

const store = useAppStore()
const newTodo = ref('')
const filter = ref('all')

const filteredTodos = computed(() => {
	if (filter.value === 'active') {
		return store.todoList.filter((t) => !t.done)
	} else if (filter.value === 'completed') {
		return store.todoList.filter((t) => t.done)
	}
	return store.todoList
})

const completedCount = computed(() => store.todoList.filter((t) => t.done).length)
const activeCount = computed(() => store.todoList.length - completedCount.value)

function addTodo() {
	const text = newTodo.value.trim()
	if (!text) return
	store.addTodo(text)
	newTodo.value = ''
}

function clearCompleted() {
	store.clearCompletedTodos()
}
</script>

<template>
	<div class="todo-widget">
		<div class="todo-widget__header">
			<el-icon class="todo-widget__icon" :size="16">
				<ElIconList />
			</el-icon>
			<span class="todo-widget__title">待办事项</span>
		</div>
		<div class="todo-widget__body">
			<div class="todo-widget__input">
				<el-input v-model="newTodo" placeholder="添加待办事项..." size="default" @keyup.enter="addTodo">
					<template #append>
						<el-button icon="ElIconPlus" @click="addTodo" />
					</template>
				</el-input>
			</div>

			<div class="todo-widget__filters">
				<el-button-group>
					<el-button :type="filter === 'all' ? 'primary' : ''" size="small" @click="filter = 'all'">全部</el-button>
					<el-button :type="filter === 'active' ? 'primary' : ''" size="small" @click="filter = 'active'">进行中</el-button>
					<el-button :type="filter === 'completed' ? 'primary' : ''" size="small" @click="filter = 'completed'">已完成</el-button>
				</el-button-group>
			</div>

			<div class="todo-widget__items">
				<div v-for="todo in filteredTodos" :key="todo.id" :class="['todo-widget__item', { 'todo-widget__item--done': todo.done }]">
					<el-checkbox v-model="todo.done" size="large" @change="store.toggleTodo(todo.id)" />
					<span class="todo-widget__text">{{ todo.text }}</span>
					<el-button class="todo-widget__delete" link type="danger" size="small" icon="ElIconDelete" @click="store.removeTodo(todo.id)" />
				</div>

				<el-empty v-if="filteredTodos.length === 0" description="暂无待办事项" :image-size="60" />
			</div>

			<div class="todo-widget__footer">
				<span class="todo-widget__count">{{ activeCount }} 项待完成</span>
				<el-button v-if="completedCount > 0" link size="small" @click="clearCompleted">清除已完成</el-button>
			</div>
		</div>
	</div>
</template>

<style scoped>
.todo-widget {
	height: 100%;
	display: flex;
	flex-direction: column;
	container-type: inline-size;
}

.todo-widget__header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--el-border-color-lighter);
	background: linear-gradient(to bottom, var(--el-bg-color-page), var(--el-bg-color));
}

.todo-widget__icon {
	color: var(--el-color-primary);
}

.todo-widget__title {
	font-size: 14px;
	font-weight: 600;
	color: var(--el-text-color-primary);
}

.todo-widget__body {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px 20px;
	overflow: hidden;
}

.todo-widget__input {
	flex-shrink: 0;
}

.todo-widget__filters {
	display: flex;
	justify-content: center;
	flex-shrink: 0;
}

.todo-widget__items {
	flex: 1;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.todo-widget__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 12px;
	border-radius: 8px;
	transition: background-color 0.2s ease;
}

.todo-widget__item:hover {
	background-color: var(--el-fill-color-light);
}

.todo-widget__item--done .todo-widget__text {
	text-decoration: line-through;
	color: var(--el-text-color-placeholder);
}

.todo-widget__text {
	flex: 1;
	font-size: 14px;
	color: var(--el-text-color-primary);
	line-height: 1.5;
}

.todo-widget__delete {
	flex-shrink: 0;
	opacity: 0;
	transition: opacity 0.2s ease;
}

.todo-widget__item:hover .todo-widget__delete {
	opacity: 1;
}

.todo-widget__footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding-top: 8px;
	border-top: 1px solid var(--el-border-color-lighter);
	flex-shrink: 0;
}

.todo-widget__count {
	font-size: 13px;
	color: var(--el-text-color-secondary);
}

/* 容器查询：窄屏压缩内边距与间距 */
@container (max-width: 420px) {
	.todo-widget__header {
		padding: 10px 12px;
	}
	.todo-widget__body {
		padding: 12px 12px;
		gap: 10px;
	}
	.todo-widget__item {
		gap: 8px;
		padding: 8px 10px;
	}
}

@container (max-width: 280px) {
	.todo-widget__header {
		padding: 8px 10px;
	}
	.todo-widget__body {
		padding: 10px;
		gap: 8px;
	}
	.todo-widget__filters {
		justify-content: flex-start;
	}
}
</style>
