# WhatToCook ERD

The visual ERD is retained as [capstone erd.jpg](../capstone%20erd.jpg). This source-level ERD records the Phase 6 deliverable schema and is easier to review alongside migrations.

```mermaid
%%{init: {'theme': 'base', 'themeVariables': { 'fontSize': '14px', 'lineColor': '#334155', 'primaryTextColor': '#0f172a', 'primaryBorderColor': '#475569', 'tertiaryColor': '#f8fafc' }}}%%
erDiagram
    USERS {
        bigint id PK
        varchar name
        varchar email UK
        timestamp email_verified_at
        varchar password
        timestamp created_at
        timestamp updated_at
    }

    PROFILES {
        bigint id PK
        bigint user_id FK, UK
        json health_conditions
        json allergies
        json dietary_restrictions
        json likes
        json dislikes
        json visible_to_family
    }

    FAMILIES {
        bigint id PK
        varchar name
        bigint owner_id FK
        varchar join_code UK
    }

    FAMILY_MEMBERS {
        bigint id PK
        bigint family_id FK
        bigint user_id FK
        varchar role
        varchar status
        bigint invited_by_user_id FK
    }

    HOUSEHOLD_PROFILES {
        bigint id PK
        bigint family_id FK
        bigint user_id FK, nullable
        varchar name
        varchar relation
        varchar sex
        date birth_date
        decimal height_cm
        decimal weight_kg
        varchar activity_level
        varchar goal
        json health_conditions
        json allergies
        json dietary_restrictions
    }

    RECIPES {
        bigint id PK
        bigint created_by FK, nullable
        varchar name
        text description
        longtext instructions
        text cooking_tips
        varchar region
        int prep_time
        int cook_time
        int servings
        varchar meal_type
        varchar difficulty
        varchar image
        decimal calories
        decimal protein
        decimal carbs
        decimal fat
    }

    INGREDIENTS {
        bigint id PK
        bigint recipe_id FK
        bigint nutrition_food_id FK, nullable
        varchar name
        varchar quantity
        varchar unit
        decimal nutrition_grams
        boolean is_substitute
    }

    NUTRITION_FOODS {
        bigint id PK
        bigint fdc_id UK, nullable
        varchar description
        varchar normalized_name
        varchar source
        json nutrients
    }

    PANTRY_ITEMS {
        bigint id PK
        bigint user_id FK
        bigint family_id FK, nullable
        varchar name
        varchar quantity
        decimal quantity_value
        varchar unit
        date purchase_date
        date expiry_date
        varchar freshness_status
        boolean is_expiry_estimated
    }

    SHOPPING_LISTS {
        bigint id PK
        bigint user_id FK
        bigint family_id FK, nullable
        varchar ingredient_name
        varchar quantity
        varchar unit
        boolean is_purchased
    }

    MEAL_PLAN_BATCHES {
        bigint id PK
        bigint user_id FK
        bigint family_id FK, nullable
        date start_date
        date end_date
        varchar status
    }

    MEAL_PLANS {
        bigint id PK
        bigint user_id FK
        bigint family_id FK, nullable
        bigint recipe_id FK
        bigint meal_plan_batch_id FK, nullable
        date planned_date
        varchar meal_type
        smallint servings
        json diner_profile_ids
        varchar status
    }

    RECIPE_FAVORITES {
        bigint id PK
        bigint user_id FK
        bigint recipe_id FK
    }

    RECIPE_REVIEWS {
        bigint id PK
        bigint user_id FK
        bigint recipe_id FK
        tinyint rating
        text review
    }

    MEAL_HISTORY {
        bigint id PK
        bigint user_id FK
        bigint family_id FK, nullable
        bigint recipe_id FK
        date prepared_at
        int servings
        text notes
    }

    INGREDIENT_PACKAGE_CONVERSIONS {
        bigint id PK
        bigint user_id FK
        bigint family_id FK, nullable
        varchar ingredient_name
        varchar package_unit
        decimal amount_per_package
        varchar amount_unit
    }

    INGREDIENT_CATALOG {
        bigint id PK
        varchar canonical_name UK
        json aliases
        varchar category
        boolean is_approved
    }

    USERS ||--|| PROFILES : has one profile
    USERS ||--o{ FAMILIES : owns
    USERS ||--o{ FAMILY_MEMBERS : joins
    FAMILIES ||--o{ FAMILY_MEMBERS : includes
    FAMILIES ||--o{ HOUSEHOLD_PROFILES : contains
    USERS ||--o{ HOUSEHOLD_PROFILES : may represent

    USERS ||--o{ PANTRY_ITEMS : personal pantry
    FAMILIES ||--o{ PANTRY_ITEMS : shared pantry
    USERS ||--o{ SHOPPING_LISTS : personal list
    FAMILIES ||--o{ SHOPPING_LISTS : shared list

    USERS ||--o{ MEAL_PLAN_BATCHES : creates
    FAMILIES ||--o{ MEAL_PLAN_BATCHES : scopes
    MEAL_PLAN_BATCHES ||--o{ MEAL_PLANS : contains
    USERS ||--o{ MEAL_PLANS : schedules
    FAMILIES ||--o{ MEAL_PLANS : scopes
    RECIPES ||--o{ MEAL_PLANS : planned for

    USERS ||--o{ RECIPE_FAVORITES : saves
    USERS ||--o{ RECIPE_REVIEWS : writes
    USERS ||--o{ MEAL_HISTORY : records
    RECIPES ||--o{ RECIPE_FAVORITES : favorited
    RECIPES ||--o{ RECIPE_REVIEWS : reviewed
    RECIPES ||--o{ MEAL_HISTORY : cooked
    RECIPES ||--o{ INGREDIENTS : contains
    NUTRITION_FOODS ||--o{ INGREDIENTS : matches

    USERS ||--o{ INGREDIENT_PACKAGE_CONVERSIONS : customizes
    FAMILIES ||--o{ INGREDIENT_PACKAGE_CONVERSIONS : shares
    INGREDIENT_CATALOG ||--o{ INGREDIENT_PACKAGE_CONVERSIONS : reference
```

This version keeps the same schema truth but reduces visual noise so it fits better in a research document or presentation page.
