# Applying the Latest Panel and Plugin Updates

This guide shows how to bring the current GitHub changes for the panel front-end and the `user-cards-bridge` WordPress plugin into your production sites.

## 1. Create a local working copy

```bash
# clone the repository (or fetch the latest commits if you already cloned it)
git clone git@github.com:<your-account>/<your-repo>.git panel-maximume
cd panel-maximume
# if you already have the repo, just run the following instead
# git checkout work
# git pull
```

> Replace `<your-account>/<your-repo>` with the correct GitHub path. The default branch in this project is `work`.

## 2. Install dependencies

The repository contains both the Vite panel and the WordPress plugin. Install dependencies before building or linting.

```bash
# Install panel dependencies
cd panel
npm install
# go back to the repo root when finished
cd ..
```

The WordPress plugin does not require a build step, but you can run `composer install` if you have PHP dependencies declared (none are required right now).

## 3. Build the panel assets (optional for production)

```bash
cd panel
npm run build
cd ..
```

The build output appears in `panel/dist`. Upload the built assets to your hosting environment if you deploy the panel statically. If you deploy via Vite dev server, use `npm run dev` instead.

## 4. Deploy the WordPress plugin

1. Copy the `user-cards-bridge` directory into the `wp-content/plugins` folder of your WordPress installation.
2. Activate (or re-activate) the plugin from the WordPress admin dashboard.
3. Visit the plugin settings page and confirm the **Panel Base URL** matches your deployed panel domain.

> After updating the plugin, purge any WordPress caching plugins so that new headers and API routing take effect immediately.

## 5. Configure the panel environment

The panel expects an environment variable to know where the WordPress API lives. Edit `panel/.env` (or create one in production) with:

```
VITE_API_BASE=https://maximumclub.ir
VITE_API_ALLOW_CROSS_ORIGIN=true
```

If you cannot make cross-origin requests from the panel host, set `VITE_API_ALLOW_CROSS_ORIGIN=false` so the panel automatically falls back to same-origin `/wp-json/...` calls.

## 6. Push the updates back to GitHub

```bash
git status         # confirm the expected files changed
git add .          # stage the updates
git commit -m "Apply latest panel and plugin fixes"
git push origin work
```

Create a pull request on GitHub if you use a different deployment branch. Once merged, redeploy your hosting environments to pick up the new commit.

## 7. Troubleshooting

* **CORS errors** – make sure the WordPress site is sending the `Access-Control-Allow-Origin` header. The latest plugin code automatically responds with either the configured panel origin or `*` as a fallback.
* **Login 404 errors** – confirm the panel is pointing at `https://maximumclub.ir/wp-json/user-cards-bridge/v1/`. Update the panel environment variables and rebuild if necessary.
* **Playback controls errors** – ensure the panel `public/speed.js` file is deployed so the DOM helpers can find the `<video>` element safely.

Following these steps keeps both the WordPress plugin and the panel front-end aligned with the latest GitHub fixes.
