export default defineNuxtPlugin((nuxtApp) => {
  const config = useRuntimeConfig()
  const apiBase = config.public.apiBase

  async function $fetchApi<T>(url: string, options: Record<string, any> = {}): Promise<T> {
    return $fetch<{ code: number; message: string; data: T }>(`${apiBase}${url}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        ...(options.headers || {}),
      },
    }).then((res) => {
      if (res.code !== 200) {
        throw new Error(res.message || 'Request failed')
      }
      return res.data as T
    })
  }

  return {
    provide: {
      api: {
        getCategories: (modelName: string) =>
          $fetchApi<any[]>(`/api/cms/category?model_name=${modelName}`),

        getContentList: (modelName: string, params: Record<string, any> = {}) => {
          const query = new URLSearchParams({ model_name: modelName, ...params }).toString()
          return $fetchApi<{ list: any[]; total: number; page: number; page_size: number }>(`/api/cms/content?${query}`)
        },

        getContentDetail: (modelName: string, id: number | string) =>
          $fetchApi<any>(`/api/cms/content/${id}?model_name=${modelName}`),
      },
    },
  }
})
