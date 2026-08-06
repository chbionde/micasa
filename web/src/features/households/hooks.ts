import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import * as api from './api'

/**
 * Chaves de cache. Centralizar evita o erro clássico de invalidar com uma
 * string e ler com outra — a lista ficaria velha na tela sem ninguém notar.
 */
export const chaves = {
  casas: ['casas'] as const,
  membros: (casaId: number) => ['casas', casaId, 'membros'] as const,
  convites: (casaId: number) => ['casas', casaId, 'convites'] as const,
}

export function useCasas() {
  return useQuery({ queryKey: chaves.casas, queryFn: api.buscarCasas })
}

export function useMembros(casaId: number | undefined) {
  return useQuery({
    queryKey: chaves.membros(casaId ?? 0),
    queryFn: () => api.buscarMembros(casaId!),
    // Sem casa ativa não há o que buscar; a query fica parada.
    enabled: casaId !== undefined,
  })
}

export function useConvites(casaId: number | undefined, habilitado: boolean) {
  return useQuery({
    queryKey: chaves.convites(casaId ?? 0),
    queryFn: () => api.buscarConvites(casaId!),
    enabled: casaId !== undefined && habilitado,
  })
}

export function useRenomearCasa(casaId: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (nome: string) => api.renomearCasa(casaId, nome),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: chaves.casas }),
  })
}

export function useAlterarPapel(casaId: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ membroId, papel }: { membroId: number; papel: 'admin' | 'member' }) =>
      api.alterarPapel(casaId, membroId, papel),
    // Invalidar marca o cache como velho: o React Query refaz a busca e a
    // tela reflete o servidor, em vez de nós adivinharmos o novo estado.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: chaves.membros(casaId) }),
  })
}

export function useRemoverMembro(casaId: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (membroId: number) => api.removerMembro(casaId, membroId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: chaves.membros(casaId) })
      // Sair da casa muda a lista de casas e a casa ativa do usuário.
      await queryClient.invalidateQueries({ queryKey: chaves.casas })
    },
  })
}

export function useCriarConvite(casaId: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (papel: 'admin' | 'member') => api.criarConvite(casaId, papel),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: chaves.convites(casaId) }),
  })
}

export function useRevogarConvite(casaId: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (conviteId: number) => api.revogarConvite(casaId, conviteId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: chaves.convites(casaId) }),
  })
}
