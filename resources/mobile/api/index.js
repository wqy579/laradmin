import request from '@/utils/request'

export default {
  // 用户相关
  user: {
    // 登录
    login: (data) => {
      return request.post('/api/auth/login', data, {
        custom: { auth: false } // 登录不需要 Token
      })
    },

    // 注册
    register: (data) => {
      return request.post('/api/auth/register', data, {
        custom: { auth: false }
      })
    },

    // 获取用户信息
    getInfo: () => {
      return request.get('/api/user/info')
    },

    // 更新用户信息
    updateInfo: (data) => {
      return request.put('/api/user/info', data)
    },

    // 修改密码
    changePassword: (data) => {
      return request.post('/api/user/password', data)
    }
  },

  // 首页相关
  home: {
    // 获取轮播图
    getBanner: () => {
      return request.get('/api/home/banner')
    },

    // 获取推荐列表
    getRecommend: (params) => {
      return request.get('/api/home/recommend', params)
    }
  },

  // 文章相关
  article: {
    // 文章列表
    getList: (params) => {
      return request.get('/api/article/list', params)
    },

    // 文章详情
    getDetail: (id) => {
      return request.get(`/api/article/detail/${id}`)
    },

    // 文章点赞
    like: (id) => {
      return request.post(`/api/article/like/${id}`)
    },

    // 收藏文章
    collect: (id) => {
      return request.post(`/api/article/collect/${id}`)
    }
  },

  // 消息相关
  message: {
    // 消息列表
    getList: (params) => {
      return request.get('/api/message/list', params)
    },

    // 未读数量
    getUnreadCount: () => {
      return request.get('/api/message/unread')
    },

    // 标记已读
    markRead: (id) => {
      return request.put(`/api/message/read/${id}`)
    },

    // 全部已读
    markAllRead: () => {
      return request.put('/api/message/read-all')
    }
  },

  // 上传相关
  upload: {
    // 上传图片
    image: (filePath, formData = {}) => {
      return request.upload('/api/upload/image', filePath, formData, {
        custom: {
          loading: true,
          loadingText: '上传中...'
        }
      })
    },

    // 上传文件
    file: (filePath, formData = {}) => {
      return request.upload('/api/upload/file', filePath, formData, {
        custom: {
          loading: true,
          loadingText: '上传中...'
        }
      })
    }
  },

  // 通用接口
  common: {
    // 获取字典
    getDict: (type) => {
      return request.get('/api/common/dict', { type })
    },

    // 获取配置
    getConfig: () => {
      return request.get('/api/common/config')
    },

    // 意见反馈
    feedback: (data) => {
      return request.post('/api/common/feedback', data)
    }
  }
}
