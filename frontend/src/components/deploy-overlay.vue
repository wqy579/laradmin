<template>
        <Transition name="deploy-fade">
                <div v-if="visible" class="deploy-overlay">
                        <div class="deploy-box">
                                <el-icon class="is-loading" :size="44"><Loading /></el-icon>
                                <p class="deploy-title">系统升级中，请稍后…</p>
                                <p class="deploy-sub">服务器正在更新，期间服务暂时中断，请勿关闭页面</p>
                        </div>
                </div>
        </Transition>
</template>

<script setup>
import { Loading } from '@element-plus/icons-vue'

defineProps({
        visible: { type: Boolean, default: false },
})
</script>

<style scoped>
.deploy-overlay {
        position: fixed;
        inset: 0;
        z-index: 99999;
        background: rgba(15, 23, 42, 0.85);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: wait;
}
.deploy-box {
        text-align: center;
        color: #fff;
}
.deploy-title {
        font-size: 22px;
        font-weight: 600;
        margin-top: 18px;
        letter-spacing: 1px;
}
.deploy-sub {
        color: rgba(255, 255, 255, 0.72);
        margin-top: 10px;
        font-size: 13px;
}
.deploy-fade-enter-active,
.deploy-fade-leave-active {
        transition: opacity 0.3s;
}
.deploy-fade-enter-from,
.deploy-fade-leave-to {
        opacity: 0;
}
</style>
