import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ReactNode } from 'react';
import MainLayout from './Layouts/MainLayout';

const appName = import.meta.env.VITE_APP_NAME || 'Zonaotaku';

createInertiaApp({
  title: (title) => (title ? `${title} · ${appName}` : appName),
  resolve: (name) => {
    const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
    const mod = pages[`./Pages/${name}.tsx`] as any;

    if (!mod) {
      throw new Error(`Inertia page not found: ${name}`);
    }

    const page = mod.default ?? mod;
    if (page && !page.layout) {
      page.layout = (pageNode: ReactNode) => <MainLayout>{pageNode}</MainLayout>;
    }

    return page;
  },
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />);
  },
  progress: {
    color: '#38bdf8',
  },
});
