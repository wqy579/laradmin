import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/web/app.js'],
            refresh: true,
        }),
		require('./vite-module-loader.js'),
		vue({
			template: {
				transformAssetUrls: {
					base: null,
					includeAbsolute: false,
				},
			},
		})
    ],
	resolve: {
	  alias: {
		'@': '/resources/web'
	  }
	}
});
