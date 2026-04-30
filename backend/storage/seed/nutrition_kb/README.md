# Nutrition Knowledge Base — Seed Source

160 raw images sourced from professional 營養師 LINE 群（2026-04-30）。Source content
covers: 蛋白質 / 碳水 / 纖維 / 油脂 / 水分 / 微量元素 / 產品搭配建議 / 客戶常見 Q&A /
減脂期 vs 維持期 / 餐次安排 etc.

## 結構

```
raw/         — 160 jpg/png 原始圖（OCR 待處理，Phase 5 啟動）
articles/    — Phase 5 產出的 markdown 結構化知識文章（按 category）
manifest.json — Phase 5 產出的索引（topic / tags / source_image / publish_at）
```

## Phase 5 OCR pipeline plan

1. Claude vision API per image → 抽取 (title, body, category, tags)
2. 人工 review + 去重 + 改寫成「朵朵語氣」
3. seed 進 `knowledge_articles` table，App 端做「每日一則營養知識」+ 主題分類瀏覽
4. 加盟者後台可標記「這篇推給特定客戶」（推送通知 + 客戶 timeline）

## 用戶 TA 對映

- **零售客戶**：減脂期怎麼吃 / 產品 + 食物搭配 / 常見謬誤澄清
- **加盟者**：服務客戶的 talking points / 不同客群的客製建議
