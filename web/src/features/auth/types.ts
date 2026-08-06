export type Household = {
  id: number
  nome: string
  fuso: string
  meu_papel: 'admin' | 'member' | null
}

export type User = {
  id: number
  name: string
  email: string
  casa_ativa: Household | null
}
