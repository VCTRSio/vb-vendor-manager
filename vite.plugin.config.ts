import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  build: {
    lib: {
      entry: 'ui/entry.tsx',
      formats: ['es'],
      fileName: () => 'entry.js',
    },
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      external: ['react', 'react-dom', 'react-dom/client', /^@vctrs\/plugin-ui/],
    },
  },
});
