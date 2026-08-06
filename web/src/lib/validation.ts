import { isAxiosError } from 'axios'

export type ValidationErrors = Record<string, string[]>

/**
 * Extrai o mapa de erros de uma resposta 422 do Laravel
 * ({ errors: { campo: [mensagens] } }); null para qualquer outro erro.
 */
export function getValidationErrors(error: unknown): ValidationErrors | null {
  if (isAxiosError(error) && error.response?.status === 422) {
    const data = error.response.data as { errors?: ValidationErrors }
    return data.errors ?? null
  }

  return null
}
