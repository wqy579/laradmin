export default () => {
  const config = useRuntimeConfig()
  const apiBase = config.public.apiBase

  async function request<T>(url: string, options: Record<string, any> = {}): Promise<T> {
    const { data, error } = await useFetch<{ code: number; message: string; data: T }>(`${apiBase}${url}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        ...(options.headers || {}),
      },
    })

    if (error.value) {
      throw createError({ statusCode: error.value.statusCode || 500, message: error.value.message })
    }

    if (data.value && data.value.code !== 200) {
      throw createError({ statusCode: data.value.code || 500, message: data.value.message })
    }

    return data.value!.data as T
  }

  function getCategories(modelName: string) {
    return request<any[]>(`/api/cms/category?model_name=${modelName}`)
  }

  function getContentList(modelName: string, params: Record<string, any> = {}) {
    const query = new URLSearchParams({ model_name: modelName, ...params }).toString()
    return request<{ list: any[]; total: number; page: number; page_size: number }>(`/api/cms/content?${query}`)
  }

  function getContentDetail(modelName: string, id: number | string) {
    return request<any>(`/api/cms/content/${id}?model_name=${modelName}`)
  }

  return {
    getCategories,
    getContentList,
    getContentDetail,
  }
}
