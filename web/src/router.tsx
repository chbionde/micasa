import { createBrowserRouter } from 'react-router'
import { GuestOnly } from './features/auth/GuestOnly'
import { RequireAuth } from './features/auth/RequireAuth'
import { AppLayout } from './layouts/AppLayout'
import { DashboardPage } from './pages/DashboardPage'
import { LoginPage } from './pages/LoginPage'
import { RegisterPage } from './pages/RegisterPage'

// Rotas aninhadas: as guardas (GuestOnly/RequireAuth) são rotas de layout —
// renderizam um <Outlet/> ou redirecionam, e os filhos herdam a proteção.
export const router = createBrowserRouter([
  {
    element: <GuestOnly />,
    children: [
      { path: '/login', element: <LoginPage /> },
      { path: '/registrar', element: <RegisterPage /> },
    ],
  },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <AppLayout />,
        children: [{ path: '/', element: <DashboardPage /> }],
      },
    ],
  },
])
