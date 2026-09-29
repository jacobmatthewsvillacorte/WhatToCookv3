# WhatToCook setup guide

## Local development

1. Install PHP 8.3+, Composer, Node.js 22+, npm, and Android Studio only if building Android.
2. In `whattocook-backend`, run `composer setup`.
3. In `frontend`, run `npm install`, then `npm start`.
4. Use `http://127.0.0.1:8000/api` for browser development. Android uses `http://192.168.254.135:8000/api` over the same Wi-Fi network.

## Android phone testing

Connect the computer and phone to the same Wi-Fi network. Start Laravel so the phone can reach it:

```powershell
cd C:\WhatToCook_v1\WhatToCook-\whattocook-backend
php artisan serve --host=0.0.0.0 --port=8000

cd ..\frontend
npm run build:android:local
npx cap open android
```

Install and run the debug app on the phone from Android Studio over USB. After unplugging the cable, keep Laravel running and leave the phone on the same Wi-Fi. The Android app connects to `http://192.168.254.135:8000/api`.

Local `.env`, database files, build output, Android signing files, and generated production API configuration remain untracked.

## Release preparation

1. Provision the HTTPS API hostname and hosted MySQL/PostgreSQL database.
2. Configure server secrets and `CORS_ALLOWED_ORIGINS` as described in [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md).
3. Run the deployment smoke test and database backup confirmation.
4. Set `WHATTOCOOK_API_BASE_URL` to the HTTPS API hostname, run `npm run build:android`, then build/sign in Android Studio.
5. Install the signed release on a physical device and complete the Android checklist in [TESTING_GUIDE.md](TESTING_GUIDE.md).
