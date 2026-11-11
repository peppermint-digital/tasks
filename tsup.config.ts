import { defineConfig } from 'tsup';

export default defineConfig({
    entry: ['resources/js/src/index.ts', 'resources/js/src/inertia/index.ts'],
    format: ['esm'],
    dts: true,
    clean: true,
    // React und Inertia bleiben beim PRODUKT. Zwei React-Instanzen im selben
    // Baum enden in „Invalid hook call", und das findet niemand schnell —
    // dieselbe Begründung wie im Mailbox-Paket, von dort übernommen.
    external: ['react', 'react-dom', '@inertiajs/react'],
});
