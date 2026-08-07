import { createBrowserRouter } from 'react-router'
import { GuestOnly } from './features/auth/GuestOnly'
import { RequireAuth } from './features/auth/RequireAuth'
import { AppLayout } from './layouts/AppLayout'
import { AceitarConvitePage } from './pages/AceitarConvitePage'
import { CasaPage } from './pages/CasaPage'
import { ContaPage } from './pages/ContaPage'
import { DashboardPage } from './pages/DashboardPage'
import { EsqueciSenhaPage } from './pages/EsqueciSenhaPage'
import { LoginPage } from './pages/LoginPage'
import { RedefinirSenhaPage } from './pages/RedefinirSenhaPage'
import { RegisterPage } from './pages/RegisterPage'

// Rotas aninhadas: as guardas (GuestOnly/RequireAuth) são rotas de layout —
// renderizam um <Outlet/> ou redirecionam, e os filhos herdam a proteção.
export const router = createBrowserRouter([
  {
    element: <GuestOnly />,
    children: [
      { path: '/login', element: <LoginPage /> },
      { path: '/registrar', element: <RegisterPage /> },
      { path: '/esqueci-senha', element: <EsqueciSenhaPage /> },
      { path: '/redefinir-senha/:token', element: <RedefinirSenhaPage /> },
    ],
  },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { path: '/', element: <DashboardPage /> },
          { path: '/casa', element: <CasaPage /> },
          { path: '/conta', element: <ContaPage /> },
          // Exige sessão: quem chega deslogado passa pelo login e volta.
          { path: '/convite/:token', element: <AceitarConvitePage /> },
        ],
      },
    ],
  },
])
