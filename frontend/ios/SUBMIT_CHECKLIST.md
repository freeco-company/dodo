# 潘朵拉飲食 iOS App Store 上架 Runbook

> 此清單是 **DevOps 已完成 Capacitor / Info.plist / Privacy 設定後**，user 必須手動在 Apple Developer Console / Xcode 執行的步驟。
> Bundle ID: `com.jerosse.pandora.meal`

---

## 0. 前置：Apple Developer 帳號 + Xcode

- [ ] Apple Developer Program（年費 USD 99）已入會、Team ID 取得
- [ ] Xcode 16+ 安裝、登入 Apple ID（Xcode > Settings > Accounts）
- [ ] CocoaPods 安裝（`sudo gem install cocoapods` 或 `brew install cocoapods`）

---

## 1. Frontend dependencies + Capacitor sync

```bash
cd /Users/chris/freeco/pandora/pandora-meal/frontend

# 1-1. 安裝新加入的 IAP plugin
npm install

# 1-2. 同步 web bundle + Capacitor plugins 到 iOS native
npx cap sync ios

# 1-3. 開啟 Xcode workspace
npx cap open ios
```

---

## 2. Apple Developer Portal — 建 App ID + Capabilities

進 https://developer.apple.com/account/resources/identifiers

- [ ] 註冊新 **App ID**：
  - Bundle ID: **`com.jerosse.pandora.meal`** (Explicit)
  - Description: `Pandora Meal`
- [ ] 啟用 Capabilities：
  - [x] **In-App Purchase**
  - [x] **Push Notifications**（FCM 用）
  - [x] **Sign in with Apple**（若有 LINE Login 或其他社群登入則必開，§4.8）
  - [x] **Associated Domains**（若要 universal links）

---

## 3. Xcode 設定（開啟 workspace 後）

### 3.1 Signing & Capabilities

1. 選 **App** target → Signing & Capabilities
2. **Team**: 選你的 Apple Developer Team
3. **Bundle Identifier**: 確認 `com.jerosse.pandora.meal`
4. 點 **+ Capability** 加入：
   - In-App Purchase
   - Push Notifications
   - Sign in with Apple（若使用）

### 3.2 Build Settings

- **MARKETING_VERSION** = `1.0.0`
- **CURRENT_PROJECT_VERSION** = `1`（每次 TestFlight 上傳 +1）
- **iOS Deployment Target** = `15.0` 以上（Capacitor 6 最低）

### 3.3 Info → 確認 PrivacyInfo.xcprivacy 已被 build phase 包含

- 在 File Navigator 確認 `App/PrivacyInfo.xcprivacy` 出現
- Target → Build Phases → Copy Bundle Resources 確認包含此檔（Xcode 通常會自動加）

---

## 4. App Store Connect — 建 App + IAP products

進 https://appstoreconnect.apple.com

### 4.1 建立 App

- [ ] My Apps → + → New App
  - Platforms: iOS
  - Name: **潘朵拉飲食**
  - Primary Language: Chinese (Traditional)
  - Bundle ID: `com.jerosse.pandora.meal`
  - SKU: `pandora-meal-ios`

### 4.2 建 IAP Subscriptions

App → 左側 **Subscriptions** → 建 Subscription Group **「潘朵拉飲食 訂閱」**：

- [ ] **com.jerosse.pandora.meal.monthly_290**
  - Reference Name: `Monthly 290`
  - Subscription Duration: 1 Month
  - Price: NT$290（Tier 對應）
  - Localized Display Name: `月訂閱`
  - Description: 中文說明

- [ ] **com.jerosse.pandora.meal.yearly_2490**
  - Reference Name: `Yearly 2490`
  - Subscription Duration: 1 Year
  - Price: NT$2,490
  - Localized Display Name: `年訂閱`
  - Description: 中文說明

- [ ] 兩個 product 都要：
  - [ ] 上傳 **Review Screenshot**（Apple 審 IAP 強制）
  - [ ] 填 Review Notes

### 4.3 取得 Apple Shared Secret（給 backend 驗 receipt）

- App → 左側 **App Information** → App-Specific Shared Secret → Generate
- 複製 secret，丟進 prod backend env：
  ```
  APPLE_IAP_SHARED_SECRET=xxx
  ```
- 同時設定 **Server-to-Server Notification URL** 指向 backend webhook（之後 IAP 接通時設定）

### 4.4 Privacy 問卷（重要）

App Privacy → Edit → 依 `PrivacyInfo.xcprivacy` 對應勾選：

- [x] Name (Linked, Not Tracking) — App Functionality
- [x] Email Address (Linked, Not Tracking) — App Functionality, Authentication
- [x] Purchase History (Linked, Not Tracking) — App Functionality, Analytics
- [x] Photos or Videos (Linked, Not Tracking) — App Functionality
- [x] Health & Fitness (Linked, Not Tracking) — App Functionality
- [x] User ID (Linked, Not Tracking) — App Functionality
- [x] Other Usage Data (Linked, Not Tracking) — Analytics, App Functionality

> **PrivacyInfo.xcprivacy 與此問卷必須一致**，否則上架會被退。

---

## 5. Push Notifications 設定（FCM）

- [ ] Apple Developer → Keys → + 建 **APNs Auth Key**（.p8）
  - 下載一次性，妥善保存
  - Key ID + Team ID 記下來
- [ ] 把 .p8 + Key ID + Team ID 上傳到 Firebase Console（Cloud Messaging tab）
- [ ] iOS app 第一次啟動跑通 push token 註冊流程

---

## 6. 第一次 Build → TestFlight

```bash
# 在 Xcode：
# 1. 選 Product → Scheme → Edit Scheme → Run → Build Configuration = Release
# 2. Product → Archive（裝置選 Any iOS Device (arm64)）
# 3. Window → Organizer → Distribute App → App Store Connect → Upload
```

- [ ] 上傳成功後在 App Store Connect → TestFlight 看到 build（約 5-30 分鐘 processing）
- [ ] 加 **Internal Testing** Testers（最多 100 人，用 Apple ID email）
- [ ] 內部測試人員裝 TestFlight App、輸入邀請碼測

---

## 7. 正式送審

- [ ] App Store Connect → App → Prepare for Submission
- [ ] 填截圖（必要尺寸：6.7" / 6.5" / 5.5"，至少 3 張）
- [ ] App Description / Keywords / Support URL / Marketing URL
- [ ] **App Review Information**：
  - Demo Account（測試帳號，必要）
  - Notes：說明 IAP 怎麼測、AI 食物辨識怎麼用
- [ ] Submit for Review
- [ ] **預計審核時間**：24-48 小時（首次可能 3-5 天）

---

## 常見退件原因 + 對策

| 原因 | 對策 |
|---|---|
| 4.8 沒有 Sign in with Apple | LINE Login 旁加 Sign in with Apple |
| 5.1.1 隱私問卷與 PrivacyInfo 不一致 | 對齊 `PrivacyInfo.xcprivacy` |
| 2.1 IAP product 沒提交 | Subscription product 必須與 binary 同時送審 |
| 4.2 minimum functionality | 確保 demo 帳號能跑通完整流程，不是空殼 |
| 食安法 / 健康宣稱 | App 內不寫「治療/減重/療效」等違規詞（集團 sanitizer 已擋） |

---

## 後續每次更新發版

```bash
cd frontend
# 改完 web 後：
npx cap sync ios

# Xcode：
# 1. Build Settings → CURRENT_PROJECT_VERSION +1
# 2. Product → Archive → Distribute → Upload
```

> TestFlight 有 build 後可直接 Promote 到 App Store（同一 build），不用重 build。

---

## Sentry crash reporting (browser SDK in WKWebView)

Backend uses `SENTRY_LARAVEL_DSN`. Frontend uses a **separate** Sentry project
(different platform = different fingerprint groups).

- [ ] 在 Sentry org 建立新 project (platform: `JavaScript / Browser`)，命名 `pandora-meal-ios`
- [ ] 取得 DSN（格式：`https://<key>@<org>.ingest.sentry.io/<project>`）
- [ ] 將 DSN 注入 `frontend/public/index.html` 的 `window.__SENTRY_DSN__`：
      建議做法是在 deploy build 時用 sed 替換 placeholder，或在 nginx
      `sub_filter` 注入。**不要 commit 真實 DSN 到 repo**。
- [ ] 確認 PII scrub 生效：`beforeSend` hook 已過濾 password / email / token / apple_id / line_id
- [ ] iOS native 層 crash（Swift / Objective-C）若需要，另外加 sentry-cocoa SDK；
      目前 WKWebView JS 錯誤已涵蓋 95% case，先跳過 native SDK
- [ ] 每次 release 在 deploy script 設 `window.__SENTRY_RELEASE__` = git sha 方便追溯
