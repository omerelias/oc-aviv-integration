# מדריך בדיקת Webhook ב-Postman

## URL של ה-Webhook Endpoint

**REST API (מומלץ):**
```
https://yoursite.com/wp-json/ocaviv/v1/getstatus
```

**או Rewrite Rule (fallback):**
```
https://yoursite.com/ocaviv/getstatus
```

> **הערה:** החלף את `yoursite.com` בכתובת האתר שלך.

---

## הגדרות ב-Postman

### 1. Method ו-URL
- **Method:** `POST`
- **URL:** `https://yoursite.com/wp-json/ocaviv/v1/getstatus`

### 2. Headers
```
Content-Type: application/json
```

### 3. Body
- בחר **raw**
- בחר **JSON** מהרשימה הנפתחת

---

## דוגמאות JSON לבדיקה

### דוגמה 1: עדכון סטטוס הזמנה

```json
{
  "type": "ACCEPTED",
  "shareToken": "17175",
  "data": "10131796",
  "timeStatus": "2025-12-21T08:12:32.055Z"
}
```

**תשובה צפויה:**
```json
{
  "error": 0,
  "errorMsg": "",
  "shareToken": "17175",
  "amount": 13990,
  "proofToken": "",
  "checkoutPayment": {
    "checkoutType": "CASH"
  }
}
```

---

### דוגמה 2: עדכון כמות פריטים (שינוי כמות בלבד)

```json
{
  "shareToken": "17175",
  "items": [
    {
      "id": "500500",
      "desc": "מוצר דמה 500 גרם (דוגמה)",
      "price": 100,
      "itemType": "PRODUCT",
      "variations": null,
      "count": 6,
      "orderedCount": 1
    }
  ],
  "total": 600
}
```

**הסבר:**
- `shareToken`: מספר ההזמנה ב-WooCommerce
- `items[].id`: SKU או Product ID של המוצר
- `items[].count`: הכמות החדשה (6 במקרה הזה)
- `items[].price`: **אופציונלי** - מחיר כולל השורה באגורות (אם לא נשלח, הכמות תתעדכן לפי מחיר יחידה קיים)

**תשובה צפויה:**
```json
{
  "error": 0,
  "errorMsg": "",
  "shareToken": "17175",
  "amount": 83940,
  "proofToken": "",
  "checkoutPayment": {
    "checkoutType": "CASH"
  }
}
```

---

### דוגמה 3: עדכון כמות + מחיר פריט

```json
{
  "shareToken": "17175",
  "items": [
    {
      "id": "500500",
      "desc": "מוצר דמה 500 גרם (דוגמה)",
      "price": 20000,
      "itemType": "PRODUCT",
      "variations": null,
      "count": 4,
      "orderedCount": 1
    }
  ],
  "total": 80000
}
```

**הסבר:**
- `items[].price`: 20000 אגורות = 200 ₪ (מחיר כולל השורה כולל מע"מ)
- `items[].count`: 4 יחידות
- המערכת תעדכן את הכמות ל-4 ואת המחיר הכולל ל-200 ₪

**תשובה צפויה:**
```json
{
  "error": 0,
  "errorMsg": "",
  "shareToken": "17175",
  "amount": 80000,
  "proofToken": "",
  "checkoutPayment": {
    "checkoutType": "CASH"
  }
}
```

---

### דוגמה 4: עדכון מספר פריטים

```json
{
  "shareToken": "17175",
  "items": [
    {
      "id": "10262",
      "desc": "פילה שייטל",
      "price": 19485,
      "itemType": "PRODUCT",
      "count": 2
    },
    {
      "id": "10284",
      "desc": "אונטריב",
      "price": 63920,
      "itemType": "PRODUCT",
      "count": 8
    }
  ],
  "total": 83405
}
```

**הסבר:**
- מעדכן 2 פריטים בו-זמנית
- פריט 1: כמות 1 → 2, מחיר כולל 194.85 ₪
- פריט 2: כמות 4 → 8, מחיר כולל 639.20 ₪

---

### דוגמה 5: שגיאה - הזמנה לא נמצאה

```json
{
  "shareToken": "99999"
}
```

**תשובה צפויה:**
```json
{
  "error": 1,
  "errorMsg": "Order not found",
  "shareToken": "99999",
  "amount": 0,
  "proofToken": "",
  "checkoutPayment": {
    "checkoutType": "CASH"
  }
}
```

**Status Code:** `404`

---

### דוגמה 6: שגיאה - שדה shareToken חסר

```json
{
  "items": [
    {
      "id": "500500",
      "count": 2
    }
  ]
}
```

**תשובה צפויה:**
```json
{
  "error": 1,
  "errorMsg": "Missing required field: shareToken",
  "shareToken": "",
  "amount": 0,
  "proofToken": "",
  "checkoutPayment": {
    "checkoutType": "CASH"
  }
}
```

**Status Code:** `400`

---

## איך למצוא את מספר ההזמנה (shareToken)?

1. היכנס ל-WooCommerce → Orders
2. פתח את ההזמנה שברצונך לבדוק
3. מספר ההזמנה מוצג בראש העמוד (למשל: `#17175`)
4. השתמש במספר הזה (ללא ה-`#`) ב-`shareToken`

---

## איך למצוא את ה-Product ID או SKU?

1. היכנס ל-WooCommerce → Products
2. פתח את המוצר
3. **SKU** מוצג תחת "Product Data" (אם קיים)
4. **Product ID** מוצג ב-URL או בתחתית העמוד (למשל: `post=10262`)

---

## בדיקת התשובה

לאחר שליחת הבקשה ב-Postman:

1. **בדוק את התשובה ב-Postman:**
   - Status Code: `200` (הצלחה) או `400`/`404` (שגיאה)
   - Body: JSON עם התשובה

2. **בדוק את הלוג ב-WooCommerce:**
   - היכנס ל-WooCommerce → Aviv POS → לוג Webhook
   - תראה את הבקשה והתשובה בטבלה

3. **בדוק את ההזמנה:**
   - אם עדכנת כמות, בדוק שההזמנה עודכנה
   - אם עדכנת סטטוס, בדוק שהסטטוס השתנה

---

## טיפים לבדיקה

1. **התחל עם הזמנה קיימת** - ודא שיש לך הזמנה עם מספר ידוע
2. **בדוק את ה-SKU/Product ID** - ודא שהמוצר קיים בהזמנה
3. **השתמש בכמויות קטנות** - התחל עם שינוי מ-1 ל-2 כדי לראות את ההבדל
4. **בדוק את המחיר** - ודא שהמחיר ליחידה לא השתנה, רק הסך הכל

---

## דוגמה מלאה לבדיקת שינוי כמות

**לפני:**
- הזמנה #17175
- פריט עם SKU `500500`
- כמות: 1
- מחיר ליחידה: 1 ₪
- סך הכל: 1 ₪

**JSON לשליחה:**
```json
{
  "shareToken": "17175",
  "items": [
    {
      "id": "500500",
      "count": 6
    }
  ]
}
```

**אחרי:**
- כמות: 6
- מחיר ליחידה: 1 ₪ (לא השתנה!)
- סך הכל: 6 ₪ (1 × 6)

**תשובה:**
```json
{
  "error": 0,
  "errorMsg": "",
  "shareToken": "17175",
  "amount": 600,
  "proofToken": "",
  "checkoutPayment": {
    "checkoutType": "CASH"
  }
}
```

---

## פתרון בעיות

### שגיאה 404 - Order not found
- ודא שמספר ההזמנה נכון
- ודא שההזמנה קיימת ב-WooCommerce

### פריט לא מתעדכן
- ודא שה-`id` תואם ל-SKU או Product ID
- בדוק שהפריט קיים בהזמנה

### המחיר ליחידה משתנה
- זה באג - דווח עליו
- המערכת אמורה לשמור על המחיר ליחידה

---

## קישורים נוספים

- **Webhook URL:** ניתן לראות בעמוד ההגדרות → לוג Webhook
- **לוגים:** WooCommerce → Aviv POS → לוג Webhook

