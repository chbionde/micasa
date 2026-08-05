import { afterEach } from 'vitest'
import { cleanup } from '@testing-library/react'
// Registra os matchers do jest-dom (toBeInTheDocument, toHaveTextContent…)
// no expect do Vitest. Importado uma vez, vale para toda a suíte.
import '@testing-library/jest-dom/vitest'

// Sem globals do Vitest, o auto-cleanup da Testing Library não se registra
// sozinho — sem isto, cada render() acumula DOM do teste anterior.
afterEach(() => {
  cleanup()
})
