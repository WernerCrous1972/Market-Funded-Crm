# Match-Trade Broker API Documentation (v1.25)

**Base URL (Sandbox):** `https://broker-api-demo.match-trader.com`

---

## Table of Contents

1. [Introduction](#introduction)
2. [Integration Guide for CRM with Match-Trader Platform](#integration-guide-for-crm)
   - [Architecture and Data Flow](#architecture-and-data-flow)
   - [Step-by-Step Integration Process](#step-by-step-integration-process)
   - [Conformance Testing](#conformance-testing)
   - [Retrieving Trading Account Financial Data](#retrieving-trading-account-financial-data)
3. [Single Sign-On (SSO) Integration Guide](#sso-integration-guide)
4. [Authentication](#authentication)
5. [gRPC Services](#grpc-services)
   - [LedgerServiceExternal](#ledgerserviceexternal)
   - [GroupServiceExternal](#groupserviceexternal)
   - [TradingEventServiceExternal](#tradingeventserviceexternal)
   - [OrderServiceExternal](#orderserviceexternal)
   - [QuotationServiceExternal](#quotationserviceexternal)
   - [AccountInfoServiceExternal](#accountinfoserviceexternal)
   - [SymbolServiceExternal](#symbolserviceexternal)
   - [PositionServiceExternal](#positionserviceexternal)
   - [EquityServiceExternal](#equityserviceexternal)
6. [Errors](#errors)
7. [Branches, Offers, Roles](#branches-offers-roles)
8. [Accounts](#accounts)
9. [Payments](#payments)
10. [Trading](#trading)
11. [Prop Trading](#prop-trading)
12. [Social Trading](#social-trading)
13. [CRM Events](#crm-events)
14. [FAQ](#faq)

---

## Introduction

The Broker API is a versatile API platform designed for various integrations, enabling streamlined management and interaction with multiple services through a single access point. This API supports REST and gRPC protocols, providing flexibility in integration and ensuring compatibility with diverse client systems. It facilitates secure, efficient administrative operations and service management, making it an essential tool for clients looking to optimise their operational workflows.

In our system, each broker has a unique `partnerID` assigned. Every operation via this API is performed within that particular partnerID. Since we encode your partnerID in your Authorization token, you don't have to consider this parameter when sending requests, but you will see this parameter across multiple different endpoints. For the Sandbox environment, `partnerID = 0` and is shared between every account in the system. It means you will have access to test data added by other integrations.

> **Server time is GMT (UTC+0).**

> **All time-related fields accept and return values according to the RFC 3339 standard.** For example `2024-01-13T09:20:04.651Z`.

> **The request limit is 500 requests/minute.**

---

## Integration Guide for CRM with Match-Trader Platform

This comprehensive integration guide provides external CRM providers with the necessary instructions for connecting their systems with the Match-Trader Platform. The following sections outline the architecture, step-by-step integration process, authentication methods, and best practices to ensure a seamless connection between your CRM solution and our trading ecosystem.

---

### Architecture and Data Flow

#### How the Match-Trader Ecosystem Works

The Match-Trader platform operates within an integrated ecosystem of three main components (Platform, Backend, CRM).

> **Important:** When integrating your system, you only need to connect with Broker-API. There is no need to directly integrate with other components as they are already interconnected.

#### Role of Individual Components

**Match-Trade CRM**
- User account management (accounts)
- Mapping of offers with groups created in the admin panel
- Ability to check client details and platform logs (also available via API)

**Match-Trade Backend** — Consists of two main modules:

*Match-Trade Admin*
- Configuration-oriented system
- Group creation and management
- Symbol, spread, and other parameter configuration

*Match-Trade Manager*
- Trading accounts overview
- Open position monitoring
- Access to ledgers, orders, and other operations

**Match-Trader Platform**
- Trading interface for end users
- Utilizes configuration defined in Admin
- Connected with CRM for account management

#### Key Concepts

**Relationship Between Groups and Offers**
- In Backend Admin, groups are created with their parameters (symbols, spreads, etc.)
- In CRM, offers are created that are visible to traders
- Each group created in the admin panel that is to be made public must be mapped to an offer
- During registration, traders see and select offers, not groups

> **IMPORTANT NOTE:** As a CRM Provider, you must integrate with offers in the CRM, not with groups in the Backend. This is the primary integration point.

**Account Structure**
- **Account** — user profile linked to an email address
  - Has personal details
  - Has one or more trading accounts
  - Is assigned roles
  - Has a unique UUID
- **Trading Account** — actual trading account
  - Has a numeric login
  - Belongs to a group (offer)
  - Has financial data (balance, equity, etc.)

---

### Step-by-Step Integration Process

#### Authentication and API Access

To begin integration with the Match-Trade CRM, you need:
- **Bearer Token (APIKey)** — obtained from the client (broker)
- **Base URL** — specific to the client's environment. Example URL for the UAT environment: `https://broker-api-demo.match-trader.com/`

> The API key should be kept secure and not shared publicly.

#### API Overview

The Match-Trade Broker API provides two interfaces:
- **REST API** — The primary interface for most integration needs
- **gRPC** — For streaming live data in advanced scenarios (e.g., monitoring stop-loss events or position openings)

#### Initial Connection Test

To verify your connection is working correctly:

```bash
curl --location '{{baseURL}}/v1/offers' \
--header 'Content-Type: application/json' \
--header 'Authorization: {{APIkey}}'
```

A successful response (HTTP 200) indicates that your authentication is working properly.

#### Key Configuration Elements

**Offers**
- Offers cannot be created via API
- They must be pre-configured by the client during setup
- Offers are linked to groups in the backend system
- You'll need to retrieve available offers UUID to create trading accounts

**Roles**
- Each trader (account) should have the 'User' role
- Other roles like 'IB' or 'Sub_IB' exist but are for our internal CRM use
- Always assign the 'User' role when creating accounts

#### Integration Steps

**Step 1: Retrieve Available Offers**

```bash
curl --location '{baseURL}/v1/offers' \
--header 'Content-Type: application/json' \
--header 'Authorization: {APIkey}'
```

The response contains important information including: `offerUuid`, `partnerId`, `groupName`, `branchUuid`, `systemUuid`, `operationUuid`. Store these values as they will be required for subsequent API calls.

**Step 2: Create User Account and Trading Account**

```bash
curl --location '{baseURL}/v1/accounts' \
--header 'Content-Type: application/json' \
--header 'Authorization: {APIkey}' \
--data-raw '{
  "email": "blatata@match-trade.com",
  "password": "abcd1234",
  "offerUuid": "offer_uuid_from_step_1",
  "personalDetails": {
    "firstname": "Testfirstname",
    "lastname": "TestLastname"
  }
}'
```

- When you specify the `offerUuid`, a user account will be created along with a trading account under that offer.
- Passwords are encrypted immediately by the system.

**Step 3 (Optional): Create Only Trading Account**

```bash
curl --location '{baseURL}/v1/accounts/{accountUuid}/trading-accounts' \
--header 'Content-Type: application/json' \
--header 'Authorization: {APIkey}' \
--data '{"offerUuid": "offer_uuid_from_step_1"}'
```

**Step 4 (Optional): Check if Account Already Exists**

```bash
curl --location '{baseURL}/v1/accounts/by-email/blatata@match-trade.com' \
--header 'Authorization: {APIkey}'
```

**Step 5: Managing Account Deposits and Withdrawals**

Important notes about payment gateways:
- Payment gateways are pre-configured by Match-Trade or the client
- For external integration, use gateways with the appropriate type
- The gateway currency must match the trading account currency
- If a gateway for a specific currency is missing, it must be manually configured by the client

Get payment gateways:
```bash
curl --location '{baseURL}/v1/payment-gateways' \
--header 'Content-Type: application/json' \
--header 'Authorization: {APIkey}'
```

Add deposit:
```bash
curl --location '{baseURL}/v1/deposits/manual' \
--header 'Content-Type: application/json' \
--header 'Authorization: {APIkey}' \
--data '{
  "systemUuid": "system_uuid",
  "login": "trading_account_login",
  "paymentGatewayUuid": "payment_gateway_uuid",
  "amount": 150,
  "comment": "testDeposit"
}'
```

Withdraw funds:
```bash
curl --location '{baseURL}/v1/withdrawals/manual' \
--header 'Content-Type: application/json' \
--header 'Authorization: {APIkey}' \
--data '{
  "systemUuid": "system_uuid",
  "login": "trading_account_login",
  "paymentGatewayUuid": "payment_gateway_uuid",
  "amount": 100,
  "comment": "testWithdrawal"
}'
```

**Step 6 (Optional): Get Open Positions**

```bash
curl --location '{{baseURL}}/v1/trading-accounts/trading-data/open-positions' \
--header 'Content-Type: application/json' \
--header 'Authorization: {{APIkey}}' \
--data '{"systemUuid": "{{systemUuid}}", "logins": ["login1","login2"], "groups": ["group_name"]}'
```

**Step 7 (Optional): Get Closed Positions History**

```bash
curl --location '{{baseURL}}/v1/trading-accounts/trading-data/closed-positions' \
--header 'Content-Type: application/json' \
--header 'Authorization: {{APIkey}}' \
--data '{
  "systemUuid": "{{systemUuid}}",
  "logins": ["login1","login2"],
  "groups": ["group_name"],
  "from": "2025-06-18T00:00:00.000Z",
  "to": "2027-05-14T00:00:00.000Z",
  "limit": 100
}'
```

**Step 8 (Optional): Get Ledgers**

```bash
curl --location '{{baseURL}}/v1/trading-accounts/trading-data/ledgers' \
--header 'Content-Type: application/json' \
--header 'Authorization: {{APIkey}}' \
--data '{"systemUuid": "{{systemUuid}}", "types": ["DEPOSIT","WITHDRAWAL"], "logins": ["login1","login2"]}'
```

---

### Conformance Testing

Before proceeding to production deployment, completing the conformance test is a mandatory step to verify your integration with the Match-Trader Platform.

**Conformance Test Process:**
1. Access the Conformance Test Template
2. Open the conformance test spreadsheet link
3. Select File > Make a copy; name your copy and save it to your Google Drive
4. Execute each test case following the provided steps
5. Document actual results in the "Result" column
6. Mark each test as "Passed", "Not passed", or "Unsupported" accordingly
7. Upon completion, share the spreadsheet with our integration team

**Integration Checklist:**
- Obtained API key and base URL from a client
- Verified API connection
- Documented available offers for reference
- Retrieved available payment gateways
- Verified currency matching between gateways and planned trading accounts
- Implemented account existence check to prevent duplicates
- Created test user account successfully
- Created a trading account linked to the test user account
- Successfully tested deposits using the manual payment gateway
- Verified deposit appears correctly in the trading account
- Successfully tested withdrawals using the manual payment gateway
- Verified withdrawal was processed correctly
- Verified login works with the created credentials
- Implemented error handling for all API calls
- Verified rate limiting compliance (under 500 req/min)
- Set up monitoring for API response times and errors

---

### Retrieving Trading Account Financial Data

This section describes all available API methods that external CRM providers (including PROP firms) can use to retrieve and monitor the status of trading accounts.

#### Real-time balance and equity stream

CRM providers operating in the Forex space typically poll accounts in bulk to get up-to-date status information such as profit, loss, and account balance.

**gRPC Stream: `getClientEquityStream`**

This stream allows real-time monitoring of equity and balance values for selected trading accounts or groups.

Authorization: `Bearer {{APIKey}}`

Request body:
```json
{
  "systemUuid": "system_uuid",
  "logins": ["login1", "login2"],
  "groups": ["group_name"]
}
```

Parameters:
- `logins`: array of specific trading account numbers to monitor
- `groups`: array of group names — recommended: only one group per connection

> **Best Practice:** Listen to one group per stream connection. This prevents future performance degradation, as a single oversized stream can degrade performance.

The `systemUuid` is a unique and immutable identifier for each server. It can be retrieved via the Get Offers endpoint.

Stream Response Example:
```json
{
  "equity": {
    "login": "413586",
    "equity": "5560.95",
    "balance": "5560.95",
    "credit": "0.00"
  }
}
```

#### Periodic Account Status Fetching via REST API

```bash
curl --location --globoff '{{baseURL}}/v1/trading-account?systemUuid={{systemUuid}}&login={{login}}' \
--header 'Content-Type: application/json' \
--header 'Authorization: {{APIKey}}'
```

**Query Parameters:**
- `systemUuid` — unique identifier of the trading server (retrievable via Get Offers)
- `login` — trading account number to be fetched

**Response example:**
```json
{
  "uuid": "3f168829-80a5-48ce-aa11-41cad249dcee",
  "login": "7198",
  "created": "2024-05-24T10:42:08.412Z",
  "accountInfo": {
    "uuid": "aafff59c-ad8d-41c6-b577-b22d1359f475",
    "email": "test@match-trade.com",
    "offerUuid": "f6cbaca3-cc96-4275-a784-12659032b544",
    "systemUuid": "8e9ed851-1e5e-479b-aa19-bade6a67d1d5",
    "commissionUuid": null,
    "group": "testUSD",
    "leverage": 100,
    "access": "FULL",
    "accountType": "REAL"
  },
  "financeInfo": {
    "balance": 618509.43,
    "equity": 618720.93,
    "margin": 0.00,
    "freeMargin": 618720.93,
    "credit": 211.50,
    "currency": "USD"
  }
}
```

---

## SSO Integration Guide

### Single Sign-On (SSO) Integration Guide for External Applications

The SSO mechanism is designed for external application → MTT platform login flow.

> This endpoint is designed for external integrations that do not store or manage user passwords. It allows generating a One-Time Token (OTT), which lets a user log in to the platform without needing their password.

**Security Requirements:**
- Your API Key must have the rights enabled to call this endpoint (API ACCESS → Create One Time Token for Login)
- The IP address used for the request must be whitelisted by our Support Team

---

### POST /v1/one-time-token

Generate a One-Time Token for SSO login.

**Parameters:**
- `email` — the user's email address (the account to generate the token for)
- `validityTime` — token expiration time in seconds

**Example cURL:**
```bash
curl --location '{baseURL}/v1/one-time-token' \
--header 'Content-Type: application/json' \
--header 'Authorization: {APIKey}' \
--data-raw '{
  "email": "test@match-trade.com",
  "validityTime": 30
}'
```

**Demo environment details:**
- baseURL: `https://broker-api-demo.match-trader.com/`
- Demo platform link: `https://mtr-demo-prod.match-trader.com/`
- Demo login: `test@match-trade.com`
- Demo password: `abcd1234`

**How to Test the One-Time Token:** After generating a token, open this link in your browser: `{platformURL}/?auth={oneTimeToken}`

**Example Scenarios:**
- **Valid login:** Generate a token valid for 30 seconds and log in within that time → login works
- **Expired token:** Generate a token valid for 30 seconds but try after it expires → token is invalid
- **Regenerated token:** If a new token is issued, the first token becomes invalid; only the latest one works

---

## Authentication

Our system implements a security mechanism that utilizes token-based authorization to ensure secure resource access. The authentication process involves using an `Authorization` header, following the Bearer token scheme.

**Security Best Practices:** All communication with our services should be performed over encrypted channels (SSL/TLS protocols). Always connect to the secure port (e.g., 443).

**Obtaining the Token:** The authentication token is obtained from the CRM system. Tokens are valid until revoked.

**Using the Token:**

Authorization: Bearer <Your_Token_Here>

**Incorporating Authorization in gRPC Requests:** In gRPC, the Authorization metadata (with the Bearer prefix) must be included with each call.

**Code Example (Python):**
```python
import grpc

# Create a secure gRPC channel (recommended)
channel = grpc.secure_channel('your_grpc_service_endpoint:443', grpc.ssl_channel_credentials())

# Prepare metadata with the Authorization token
metadata = [('Authorization', 'Bearer <Your_Token_Here>')]

# Create a stub (client)
stub = YourServiceStub(channel)

# Make a call with the Authorization metadata
response = stub.YourRpcMethod(request, metadata=metadata)
```

---

## gRPC Services

This gRPC model defines several services within a package designed for the Broker API, focusing on providing data streams.

**gRPC address:** `grpc-broker-api-demo.match-trader.com`

A separate gRPC ping stream sends pings every 50 seconds (configurable) to maintain an active connection.

---

### LedgerServiceExternal

#### GetLedgersByLoginStream

**Method:** Server streaming RPC  
**Description:** Streams ledger entries for a specific trading account login.

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Unique identifier of the trading server |
| login | string | Trading account login number |

**Response stream (LedgerMessage):**
| Field | Type | Description |
|-------|------|-------------|
| login | string | Trading account login |
| type | string | Ledger type (e.g., DEPOSIT, WITHDRAWAL, CREDIT_IN, etc.) |
| amount | string | Transaction amount |
| comment | string | Transaction comment |
| time | string | Timestamp of the transaction |
| orderId | string | Associated order ID (if applicable) |

---

#### GetLedgersByGroupsOrLoginsStream

**Method:** Server streaming RPC  
**Description:** Streams ledger entries for multiple logins or groups.

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Unique identifier of the trading server |
| logins | repeated string | List of trading account logins |
| groups | repeated string | List of group names |

**Response stream (LedgerMessage):** Same fields as above.

---

### GroupServiceExternal

#### GetGroupsStream

**Method:** Server streaming RPC  
**Description:** Streams group data from the trading server.

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Unique identifier of the trading server |

**Response stream (GroupMessage):**
| Field | Type | Description |
|-------|------|-------------|
| name | string | Group name |
| currency | string | Group currency |
| description | string | Group description |

---

### TradingEventServiceExternal

#### GetTradingEventsStream

**Method:** Server streaming RPC  
**Description:** Streams real-time trading events (position opens, closes, modifications).

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Unique identifier of the trading server |
| logins | repeated string | (Optional) Filter by specific logins |
| groups | repeated string | (Optional) Filter by group names |

**Response stream (TradingEventMessage):**
| Field | Type | Description |
|-------|------|-------------|
| login | string | Trading account login |
| event | string | Event type (OPEN, CLOSE, MODIFY, etc.) |
| positionId | string | Position identifier |
| symbol | string | Trading symbol |
| volume | string | Trade volume |
| openPrice | string | Open price |
| closePrice | string | Close price |
| profit | string | Trade profit |
| time | string | Event timestamp |

---

### OrderServiceExternal

#### GetOrdersStream

**Method:** Server streaming RPC  
**Description:** Streams pending order data.

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Unique identifier of the trading server |
| logins | repeated string | (Optional) Filter by specific logins |
| groups | repeated string | (Optional) Filter by group names |

**Response stream (OrderMessage):**
| Field | Type | Description |
|-------|------|-------------|
| login | string | Trading account login |
| orderId | string | Order identifier |
| symbol | string | Trading symbol |
| type | string | Order type (BUY_LIMIT, SELL_LIMIT, etc.) |
| volume | string | Order volume |
| price | string | Order price |
| stopLoss | string | Stop loss level |
| takeProfit | string | Take profit level |
| time | string | Order creation timestamp |

---

### QuotationServiceExternal

#### GetQuotationsStream

**Method:** Server streaming RPC  
**Description:** Streams live market quotations (bid/ask prices) for symbols.

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Unique identifier of the trading server |
| symbols | repeated string | List of symbol names to stream quotes for |

**Response stream (QuotationMessage):**
| Field | Type | Description |
|-------|------|-------------|
| symbol | string | Trading symbol |
| bid | string | Bid price |
| ask | string | Ask price |
| time | string | Quote timestamp |

---

### AccountInfoServiceExternal

#### GetAccountInfoStream

**Method:** Server streaming RPC  
**Description:** Streams account information including financial data.

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Unique identifier of the trading server |
| logins | repeated string | List of trading account logins |

**Response stream (AccountInfoMessage):**
| Field | Type | Description |
|-------|------|-------------|
| login | string | Trading account login |
| balance | string | Account balance |
| equity | string | Account equity |
| margin | string | Used margin |
| freeMargin | string | Free margin |
| credit | string | Credit amount |
| group | string | Group name |

---

### SymbolServiceExternal

#### GetSymbolsStream

**Method:** Server streaming RPC  
**Description:** Streams symbol (instrument) configuration data.

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Unique identifier of the trading server |

**Response stream (SymbolMessage):**
| Field | Type | Description |
|-------|------|-------------|
| name | string | Symbol name |
| description | string | Symbol description |
| digits | int32 | Number of decimal places |
| contractSize | string | Contract size |
| currency | string | Symbol currency |

---

### PositionServiceExternal

#### GetPositionsStream (by Groups or Logins)

**Method:** Server streaming RPC  
**Description:** Streams open position data for specified groups or logins.

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Unique identifier of the trading server |
| logins | repeated string | (Optional) Filter by specific logins |
| groups | repeated string | (Optional) Filter by group names |

**Response stream (PositionMessage):**
| Field | Type | Description |
|-------|------|-------------|
| login | string | Trading account login |
| positionId | string | Position identifier |
| symbol | string | Trading symbol |
| type | string | Position type (BUY/SELL) |
| volume | string | Position volume |
| openPrice | string | Open price |
| stopLoss | string | Stop loss |
| takeProfit | string | Take profit |
| profit | string | Current profit |
| swap | string | Swap charges |
| openTime | string | Position open timestamp |

#### GetPositionsByLoginStream

Same as above but filtered to a single login.

---

### EquityServiceExternal

#### GetClientEquityStream

**Method:** Server streaming RPC  
**Description:** Streams real-time equity and balance data for trading accounts.

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Unique identifier of the trading server |
| logins | repeated string | Specific trading account logins |
| groups | repeated string | Group names |

**Response stream:**
| Field | Type | Description |
|-------|------|-------------|
| login | string | Trading account login |
| equity | string | Current equity value |
| balance | string | Current balance |
| credit | string | Credit amount |

---

## Errors

### HTTP Error Codes

The API uses standard HTTP status codes to indicate the success or failure of an API request.

| Status Code | Meaning |
|-------------|---------|
| 200 | OK — Request was successful |
| 201 | Created — Resource was successfully created |
| 400 | Bad Request — Invalid parameters or request body |
| 401 | Unauthorized — Invalid or missing authentication token |
| 403 | Forbidden — Insufficient permissions |
| 404 | Not Found — Resource does not exist |
| 409 | Conflict — Resource already exists or conflicting state |
| 500 | Internal Server Error — Server-side error |

### gRPC Error Handling

gRPC uses its own status codes. Common codes:

| gRPC Status | Meaning |
|-------------|---------|
| OK (0) | Success |
| CANCELLED (1) | Operation was cancelled |
| UNKNOWN (2) | Unknown error |
| INVALID_ARGUMENT (3) | Invalid request parameters |
| NOT_FOUND (5) | Resource not found |
| ALREADY_EXISTS (6) | Resource already exists |
| PERMISSION_DENIED (7) | Insufficient permissions |
| UNAUTHENTICATED (16) | Invalid authentication credentials |
| INTERNAL (13) | Internal server error |

### Error Response Examples

**REST Error Example:**
```json
{
  "timestamp": "2024-01-13T09:20:04.651Z",
  "status": 400,
  "error": "Bad Request",
  "message": "Validation failed for field 'email'",
  "path": "/v1/accounts"
}
```

**gRPC Error Example:**

Status: INVALID_ARGUMENT Message: "Field 'systemUuid' is required"
---

## Branches, Offers, Roles

### GET /v1/branches

Retrieve a list of all branches.

**Method:** `GET`  
**Path:** `/v1/branches`  
**Authorization:** `Bearer {{APIKey}}`

**Response:**
```json
[
  {
    "uuid": "string",
    "name": "string",
    "partnerId": 0
  }
]
```

---

### GET /v1/offers

Retrieve a list of all available offers.

**Method:** `GET`  
**Path:** `/v1/offers`  
**Authorization:** `Bearer {{APIKey}}`

**Response:**
```json
[
  {
    "offerUuid": "string",
    "partnerId": 0,
    "name": "string",
    "groupName": "string",
    "branchUuid": "string",
    "systemUuid": "string",
    "operationUuid": "string",
    "currency": "string",
    "leverage": 0,
    "accountType": "REAL | DEMO",
    "initialDeposit": 0
  }
]
```

---

### GET /v1/roles

Retrieve a list of all roles.

**Method:** `GET`  
**Path:** `/v1/roles`  
**Authorization:** `Bearer {{APIKey}}`

**Response:**
```json
[
  {
    "uuid": "string",
    "name": "string",
    "partnerId": 0
  }
]
```

---

## Accounts

### GET /v1/accounts

Retrieve a list of all accounts.

**Method:** `GET`  
**Path:** `/v1/accounts`  
**Authorization:** `Bearer {{APIKey}}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| page | integer | No | Page number (default: 0) |
| size | integer | No | Page size (default: 20) |
| sort | string | No | Sort field and direction |

**Response:**
```json
{
  "content": [
    {
      "uuid": "string",
      "email": "string",
      "personalDetails": {
        "firstname": "string",
        "lastname": "string",
        "phone": "string",
        "country": "string",
        "city": "string",
        "address": "string",
        "zipCode": "string",
        "birthday": "string"
      },
      "roles": ["string"],
      "partnerId": 0,
      "created": "string",
      "lastLogin": "string",
      "status": "string"
    }
  ],
  "totalElements": 0,
  "totalPages": 0,
  "size": 20,
  "number": 0
}
```

---

### GET /v1/accounts/by-email/{email}

Retrieve an account by email address.

**Method:** `GET`  
**Path:** `/v1/accounts/by-email/{email}`  
**Authorization:** `Bearer {{APIKey}}`

**Path Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| email | string | Yes | Account email address |

**Response:** Account object (same as above)

---

### GET /v1/accounts/{uuid}

Retrieve an account by UUID.

**Method:** `GET`  
**Path:** `/v1/accounts/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

**Path Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| uuid | string | Yes | Account UUID |

---

### GET /v1/accounts/{uuid}/timeline-events

Retrieve timeline events for an account.

**Method:** `GET`  
**Path:** `/v1/accounts/{uuid}/timeline-events`  
**Authorization:** `Bearer {{APIKey}}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| page | integer | No | Page number |
| size | integer | No | Page size |
| type | string | No | Event type filter |
| from | string | No | Start date (RFC 3339) |
| to | string | No | End date (RFC 3339) |

---

### GET /v1/accounts/managers

Retrieve a list of all managers.

**Method:** `GET`  
**Path:** `/v1/accounts/managers`  
**Authorization:** `Bearer {{APIKey}}`

---

### POST /v1/accounts

Create a new account.

**Method:** `POST`  
**Path:** `/v1/accounts`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "email": "string",
  "password": "string",
  "offerUuid": "string",
  "roleUuid": "string",
  "personalDetails": {
    "firstname": "string",
    "lastname": "string",
    "phone": "string",
    "country": "string",
    "city": "string",
    "address": "string",
    "zipCode": "string",
    "birthday": "string"
  },
  "branchUuid": "string",
  "managedByUuid": "string"
}
```

**Response:** Created account object

---

### PUT /v1/accounts/{uuid}

Update an existing account.

**Method:** `PUT`  
**Path:** `/v1/accounts/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

**Path Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| uuid | string | Yes | Account UUID |

**Request Body:** Account fields to update (same fields as create, all optional)

---

### PUT /v1/accounts/{uuid}/change-password

Change account password.

**Method:** `PUT`  
**Path:** `/v1/accounts/{uuid}/change-password`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "password": "string"
}
```

---

### DELETE /v1/accounts/bulk-delete

Bulk delete accounts.

**Method:** `DELETE`  
**Path:** `/v1/accounts/bulk-delete`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "uuids": ["string"]
}
```

---

### POST /v1/accounts/{uuid}/notes

Add a note to an account.

**Method:** `POST`  
**Path:** `/v1/accounts/{uuid}/notes`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "content": "string"
}
```

---

### POST /v1/accounts/{uuid}/tasks

Add a task to an account.

**Method:** `POST`  
**Path:** `/v1/accounts/{uuid}/tasks`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "title": "string",
  "description": "string",
  "dueDate": "string",
  "priority": "LOW | MEDIUM | HIGH"
}
```

---

### POST /v1/accounts/{uuid}/inbox-messages

Send an inbox message to an account.

**Method:** `POST`  
**Path:** `/v1/accounts/{uuid}/inbox-messages`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "subject": "string",
  "content": "string"
}
```

---

### GET /v1/lead-statuses

Retrieve available lead statuses.

**Method:** `GET`  
**Path:** `/v1/lead-statuses`  
**Authorization:** `Bearer {{APIKey}}`

---

## Accounts — Trading Accounts

### GET /v1/accounts/{uuid}/trading-accounts

Retrieve all trading accounts for an account.

**Method:** `GET`  
**Path:** `/v1/accounts/{uuid}/trading-accounts`  
**Authorization:** `Bearer {{APIKey}}`

**Response:**
```json
[
  {
    "uuid": "string",
    "login": "string",
    "systemUuid": "string",
    "offerUuid": "string",
    "group": "string",
    "leverage": 0,
    "access": "FULL | READ_ONLY | NO_TRADING | CLOSE_ONLY",
    "accountType": "REAL | DEMO",
    "balance": 0,
    "equity": 0,
    "margin": 0,
    "freeMargin": 0,
    "credit": 0,
    "currency": "string",
    "created": "string"
  }
]
```

---

### GET /v1/accounts/{uuid}/trading-accounts/{login}

Retrieve a specific trading account by login.

**Method:** `GET`  
**Path:** `/v1/accounts/{uuid}/trading-accounts/{login}`  
**Authorization:** `Bearer {{APIKey}}`

---

### POST /v1/accounts/{uuid}/trading-accounts

Create a new trading account for an account.

**Method:** `POST`  
**Path:** `/v1/accounts/{uuid}/trading-accounts`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "offerUuid": "string",
  "leverage": 0,
  "accountType": "REAL | DEMO"
}
```

---

### PUT /v1/accounts/{uuid}/trading-accounts/{login}

Update a trading account.

**Method:** `PUT`  
**Path:** `/v1/accounts/{uuid}/trading-accounts/{login}`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "access": "FULL | READ_ONLY | NO_TRADING | CLOSE_ONLY",
  "group": "string"
}
```

---

### PUT /v1/accounts/{uuid}/trading-accounts/{login}/change-leverage

Change leverage on a trading account.

**Method:** `PUT`  
**Path:** `/v1/accounts/{uuid}/trading-accounts/{login}/change-leverage`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "leverage": 0
}
```

---

### DELETE /v1/accounts/{uuid}/trading-accounts/bulk-delete

Bulk delete trading accounts.

**Method:** `DELETE`  
**Path:** `/v1/accounts/{uuid}/trading-accounts/bulk-delete`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "logins": ["string"]
}
```

---

### GET /v1/trading-account

Retrieve a single trading account by systemUuid and login.

**Method:** `GET`  
**Path:** `/v1/trading-account`  
**Authorization:** `Bearer {{APIKey}}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| systemUuid | string | Yes | Trading server UUID |
| login | string | Yes | Trading account login |

---

## Payments

### GET /v1/payment-gateways

Retrieve a list of all payment gateways.

**Method:** `GET`  
**Path:** `/v1/payment-gateways`  
**Authorization:** `Bearer {{APIKey}}`

**Response:**
```json
[
  {
    "uuid": "string",
    "name": "string",
    "currency": "string",
    "type": "string",
    "partnerId": 0
  }
]
```

---

### GET /v1/deposits

Retrieve a list of deposits.

**Method:** `GET`  
**Path:** `/v1/deposits`  
**Authorization:** `Bearer {{APIKey}}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| page | integer | No | Page number |
| size | integer | No
| size | integer | No | Page size |
| from | string | No | Start date (RFC 3339) |
| to | string | No | End date (RFC 3339) |
| accountUuid | string | No | Filter by account UUID |

---

### GET /v1/withdrawals

Retrieve a list of withdrawals.

**Method:** `GET`  
**Path:** `/v1/withdrawals`  
**Authorization:** `Bearer {{APIKey}}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| page | integer | No | Page number |
| size | integer | No | Page size |
| from | string | No | Start date (RFC 3339) |
| to | string | No | End date (RFC 3339) |
| accountUuid | string | No | Filter by account UUID |

---

### POST /v1/deposits/manual

Create a manual deposit to a trading account.

**Method:** `POST`  
**Path:** `/v1/deposits/manual`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "login": "string",
  "paymentGatewayUuid": "string",
  "amount": 0,
  "comment": "string"
}
```

**Response:**
```json
{
  "uuid": "string",
  "login": "string",
  "amount": 0,
  "currency": "string",
  "status": "string",
  "created": "string"
}
```

---

### POST /v1/withdrawals/manual

Create a manual withdrawal from a trading account.

**Method:** `POST`  
**Path:** `/v1/withdrawals/manual`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "login": "string",
  "paymentGatewayUuid": "string",
  "amount": 0,
  "comment": "string"
}
```

---

### POST /v1/credit-in

Add credit to a trading account.

**Method:** `POST`  
**Path:** `/v1/credit-in`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "login": "string",
  "amount": 0,
  "comment": "string"
}
```

---

### POST /v1/credit-out

Remove credit from a trading account.

**Method:** `POST`  
**Path:** `/v1/credit-out`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "login": "string",
  "amount": 0,
  "comment": "string"
}
```

---

## Trading

### GET /v1/symbols

Retrieve a list of available trading symbols.

**Method:** `GET`  
**Path:** `/v1/symbols`  
**Authorization:** `Bearer {{APIKey}}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| systemUuid | string | Yes | Trading server UUID |

**Response:**
```json
[
  {
    "name": "string",
    "description": "string",
    "digits": 0,
    "contractSize": 0,
    "currency": "string",
    "type": "string"
  }
]
```

---

### POST /v1/positions/open

Open a new trading position.

**Method:** `POST`  
**Path:** `/v1/positions/open`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "login": "string",
  "symbol": "string",
  "type": "BUY | SELL",
  "volume": 0,
  "stopLoss": 0,
  "takeProfit": 0,
  "comment": "string"
}
```

**Response:**
```json
{
  "positionId": "string",
  "login": "string",
  "symbol": "string",
  "type": "string",
  "volume": 0,
  "openPrice": 0,
  "openTime": "string"
}
```

---

### POST /v1/orders/pending

Create a pending order.

**Method:** `POST`  
**Path:** `/v1/orders/pending`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "login": "string",
  "symbol": "string",
  "type": "BUY_LIMIT | SELL_LIMIT | BUY_STOP | SELL_STOP",
  "volume": 0,
  "price": 0,
  "stopLoss": 0,
  "takeProfit": 0,
  "expiration": "string",
  "comment": "string"
}
```

---

### DELETE /v1/orders/{orderId}

Cancel a pending order.

**Method:** `DELETE`  
**Path:** `/v1/orders/{orderId}`  
**Authorization:** `Bearer {{APIKey}}`

**Path Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| orderId | string | Yes | Order ID to cancel |

**Request Body:**
```json
{
  "systemUuid": "string",
  "login": "string"
}
```

---

### POST /v1/orders/correction

Create a correction order.

**Method:** `POST`  
**Path:** `/v1/orders/correction`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "login": "string",
  "symbol": "string",
  "type": "BUY | SELL",
  "volume": 0,
  "price": 0,
  "comment": "string"
}
```

---

### PUT /v1/positions/{positionId}

Edit an open position (modify SL/TP).

**Method:** `PUT`  
**Path:** `/v1/positions/{positionId}`  
**Authorization:** `Bearer {{APIKey}}`

**Path Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| positionId | string | Yes | Position ID to modify |

**Request Body:**
```json
{
  "systemUuid": "string",
  "login": "string",
  "stopLoss": 0,
  "takeProfit": 0
}
```

---

### DELETE /v1/positions/{positionId}

Close an open position.

**Method:** `DELETE`  
**Path:** `/v1/positions/{positionId}`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "login": "string"
}
```

---

### DELETE /v1/positions/{positionId}/partial

Close a position partially.

**Method:** `DELETE`  
**Path:** `/v1/positions/{positionId}/partial`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "login": "string",
  "volume": 0
}
```

---

### DELETE /v1/positions/close-all

Close all open positions for a trading account.

**Method:** `DELETE`  
**Path:** `/v1/positions/close-all`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "login": "string"
}
```

---

### POST /v1/positions/{positionId}/reopen

Reopen a previously closed position.

**Method:** `POST`  
**Path:** `/v1/positions/{positionId}/reopen`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "login": "string"
}
```

---

## Trading Data

### POST /v1/trading-accounts/trading-data/open-positions

Retrieve open positions.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/open-positions`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "logins": ["string"],
  "groups": ["string"]
}
```

**Response:**
```json
[
  {
    "positionId": "string",
    "login": "string",
    "symbol": "string",
    "type": "BUY | SELL",
    "volume": 0,
    "openPrice": 0,
    "currentPrice": 0,
    "stopLoss": 0,
    "takeProfit": 0,
    "profit": 0,
    "swap": 0,
    "openTime": "string",
    "comment": "string"
  }
]
```

---

### POST /v1/trading-accounts/trading-data/closed-positions

Retrieve closed positions history.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/closed-positions`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "logins": ["string"],
  "groups": ["string"],
  "from": "string",
  "to": "string",
  "includeLocked": true,
  "includeBlocked": true,
  "limit": 100
}
```

---

### POST /v1/trading-accounts/trading-data/active-orders

Retrieve active (pending) orders.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/active-orders`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "logins": ["string"],
  "groups": ["string"]
}
```

---

### POST /v1/trading-accounts/trading-data/ledgers

Retrieve ledger entries.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/ledgers`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "logins": ["string"],
  "groups": ["string"],
  "types": ["DEPOSIT", "WITHDRAWAL", "CREDIT_IN", "CREDIT_OUT"],
  "from": "string",
  "to": "string",
  "limit": 100
}
```

---

### GET /v1/trading-accounts/trading-data/groups

Retrieve all groups from a trading server.

**Method:** `GET`  
**Path:** `/v1/trading-accounts/trading-data/groups`  
**Authorization:** `Bearer {{APIKey}}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| systemUuid | string | Yes | Trading server UUID |

---

### GET /v1/trading-accounts/trading-data/group-names

Retrieve group names from a trading server.

**Method:** `GET`  
**Path:** `/v1/trading-accounts/trading-data/group-names`  
**Authorization:** `Bearer {{APIKey}}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| systemUuid | string | Yes | Trading server UUID |

---

### POST /v1/trading-accounts/trading-data/orders-by-logins-or-groups

Retrieve orders filtered by logins or groups.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/orders-by-logins-or-groups`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "logins": ["string"],
  "groups": ["string"],
  "from": "string",
  "to": "string",
  "limit": 100
}
```

---

### POST /v1/trading-accounts/trading-data/ledgers-by-logins-or-groups

Retrieve ledgers filtered by logins or groups.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/ledgers-by-logins-or-groups`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "logins": ["string"],
  "groups": ["string"],
  "types": ["string"],
  "from": "string",
  "to": "string",
  "limit": 100
}
```

---

### POST /v1/trading-accounts/trading-data/open-positions-by-logins-or-groups

Retrieve open positions filtered by logins or groups.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/open-positions-by-logins-or-groups`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "logins": ["string"],
  "groups": ["string"]
}
```

---

### POST /v1/trading-accounts/trading-data/closed-positions-by-logins-or-groups

Retrieve closed positions filtered by logins or groups.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/closed-positions-by-logins-or-groups`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "logins": ["string"],
  "groups": ["string"],
  "from": "string",
  "to": "string",
  "limit": 100
}
```

---

### POST /v1/trading-accounts/trading-data/orders-by-ids

Retrieve orders by specific order IDs.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/orders-by-ids`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "orderIds": ["string"]
}
```

---

### POST /v1/trading-accounts/trading-data/closed-positions-by-id

Retrieve a closed position by ID.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/closed-positions-by-id`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "positionId": "string"
}
```

---

### POST /v1/trading-accounts/trading-data/open-positions-by-id

Retrieve an open position by ID.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/open-positions-by-id`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "positionId": "string"
}
```

---

### POST /v1/trading-accounts/trading-data/active-orders-by-id

Retrieve an active order by ID.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/active-orders-by-id`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "orderId": "string"
}
```

---

### POST /v1/trading-accounts/trading-data/platform-logs-v2

Retrieve platform logs (v2).

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/platform-logs-v2`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "logins": ["string"],
  "from": "string",
  "to": "string",
  "limit": 100
}
```

---

### POST /v1/trading-accounts/trading-data/balance-snapshots

Retrieve balance snapshots.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/balance-snapshots`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "logins": ["string"],
  "from": "string",
  "to": "string"
}
```

**Response:**
```json
[
  {
    "login": "string",
    "balance": 0,
    "equity": 0,
    "credit": 0,
    "timestamp": "string"
  }
]
```

---

### POST /v1/trading-accounts/trading-data/candles

Retrieve OHLCV candle data for a symbol.

**Method:** `POST`  
**Path:** `/v1/trading-accounts/trading-data/candles`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "systemUuid": "string",
  "symbol": "string",
  "period": "M1 | M5 | M15 | M30 | H1 | H4 | D1 | W1 | MN1",
  "from": "string",
  "to": "string",
  "limit": 100
}
```

**Response:**
```json
[
  {
    "time": "string",
    "open": 0,
    "high": 0,
    "low": 0,
    "close": 0,
    "volume": 0
  }
]
```

---

## Prop Trading

### Prop gRPC Services

#### PropAccountServiceExternal — GetPropAccountsStream

**Method:** Server streaming RPC  
**Description:** Streams prop trading account data.

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Trading server UUID |
| logins | repeated string | Filter by logins |
| groups | repeated string | Filter by groups |

**Response stream:**
| Field | Type | Description |
|-------|------|-------------|
| login | string | Trading account login |
| challengeUuid | string | Associated challenge UUID |
| status | string | Account status |
| balance | string | Current balance |
| equity | string | Current equity |

---

#### PropTargetServiceExternal — GetPropTargetsStream

**Method:** Server streaming RPC  
**Description:** Streams prop trading target/rule data for accounts.

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Trading server UUID |
| logins | repeated string | Filter by logins |

**Response stream:**
| Field | Type | Description |
|-------|------|-------------|
| login | string | Trading account login |
| targetType | string | Type of target rule |
| targetValue | string | Target threshold value |
| currentValue | string | Current value |
| passed | bool | Whether target is passed |

---

#### PropStatusServiceExternal — GetPropStatusStream

**Method:** Server streaming RPC  
**Description:** Streams prop account status updates.

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Trading server UUID |
| logins | repeated string | Filter by logins |

**Response stream:**
| Field | Type | Description |
|-------|------|-------------|
| login | string | Trading account login |
| status | string | ACTIVE, PASSED, FAILED, BREACH |

---

#### PropMaxLossServiceExternal — GetPropMaxLossStream

**Method:** Server streaming RPC  
**Description:** Streams max loss/drawdown data for prop accounts.

**Request:**
| Field | Type | Description |
|-------|------|-------------|
| systemUuid | string | Trading server UUID |
| logins | repeated string | Filter by logins |

**Response stream:**
| Field | Type | Description |
|-------|------|-------------|
| login | string | Trading account login |
| maxLoss | string | Maximum allowed loss |
| currentLoss | string | Current loss |
| breached | bool | Whether max loss is breached |

---

#### PropEvaluationServiceExternal — GetPropEvaluationStream

**Method:** Server streaming RPC  
**Description:** Streams evaluation progress for prop trading accounts.

**Request / Response:** Similar pattern — streams evaluation metrics per login.

---

#### PropEvaluationStatusServiceExternal — GetPropEvaluationStatusStream

**Method:** Server streaming RPC  
**Description:** Streams evaluation status (pass/fail) for prop accounts.

---

### Prop Configuration

#### GET /v1/prop/configuration/general

Retrieve general prop trading configuration.

**Method:** `GET`  
**Path:** `/v1/prop/configuration/general`  
**Authorization:** `Bearer {{APIKey}}`

**Response:**
```json
{
  "enabled": true,
  "defaultChallengeUuid": "string",
  "maxActiveAccounts": 0,
  "currency": "string"
}
```

---

### Prop Challenges

#### GET /v1/prop/challenges

Retrieve a list of all prop challenges.

**Method:** `GET`  
**Path:** `/v1/prop/challenges`  
**Authorization:** `Bearer {{APIKey}}`

**Response:**
```json
[
  {
    "uuid": "string",
    "name": "string",
    "description": "string",
    "price": 0,
    "currency": "string",
    "initialBalance": 0,
    "profitTarget": 0,
    "maxDailyLoss": 0,
    "maxTotalLoss": 0,
    "minTradingDays": 0,
    "maxTradingDays": 0,
    "offerUuid": "string",
    "status": "ACTIVE | INACTIVE"
  }
]
```

---

#### GET /v1/prop/challenges/statistics

Retrieve statistics for prop challenges.

**Method:** `GET`  
**Path:** `/v1/prop/challenges/statistics`  
**Authorization:** `Bearer {{APIKey}}`

**Response:**
```json
{
  "totalChallenges": 0,
  "activeChallenges": 0,
  "passedChallenges": 0,
  "failedChallenges": 0
}
```

---

#### GET /v1/prop/challenges/{uuid}

Retrieve a specific prop challenge by UUID.

**Method:** `GET`  
**Path:** `/v1/prop/challenges/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

---

#### POST /v1/prop/challenges

Create a new prop challenge.

**Method:** `POST`  
**Path:** `/v1/prop/challenges`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "name": "string",
  "description": "string",
  "price": 0,
  "currency": "string",
  "initialBalance": 0,
  "profitTarget": 0,
  "maxDailyLoss": 0,
  "maxTotalLoss": 0,
  "minTradingDays": 0,
  "maxTradingDays": 0,
  "offerUuid": "string"
}
```

---

#### PUT /v1/prop/challenges/{uuid}

Update a prop challenge.

**Method:** `PUT`  
**Path:** `/v1/prop/challenges/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:** Same fields as create (all optional for update)

---

### Prop Trading Accounts

#### GET /v1/prop/trading-accounts

Retrieve all prop trading accounts.

**Method:** `GET`  
**Path:** `/v1/prop/trading-accounts`  
**Authorization:** `Bearer {{APIKey}}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| page | integer | No | Page number |
| size | integer | No | Page size |
| status | string | No | Filter by status |

**Response:**
```json
{
  "content": [
    {
      "uuid": "string",
      "login": "string",
      "challengeUuid": "string",
      "accountUuid": "string",
      "systemUuid": "string",
      "status": "ACTIVE | PASSED | FAILED | BREACH",
      "phase": "string",
      "startDate": "string",
      "endDate": "string"
    }
  ],
  "totalElements": 0,
  "totalPages": 0
}
```

---

#### GET /v1/prop/trading-accounts/{uuid}

Retrieve a specific prop trading account by UUID.

**Method:** `GET`  
**Path:** `/v1/prop/trading-accounts/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

---

#### POST /v1/prop/trading-accounts

Create a new prop trading account.

**Method:** `POST`  
**Path:** `/v1/prop/trading-accounts`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "accountUuid": "string",
  "challengeUuid": "string",
  "systemUuid": "string",
  "offerUuid": "string"
}
```

---

#### PUT /v1/prop/trading-accounts/{uuid}

Update a prop trading account.

**Method:** `PUT`  
**Path:** `/v1/prop/trading-accounts/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "status": "ACTIVE | PASSED | FAILED | BREACH",
  "phase": "string"
}
```

---

#### POST /v1/prop/trading-accounts/{uuid}/force-snapshot

Force a snapshot for a prop trading account.

**Method:** `POST`  
**Path:** `/v1/prop/trading-accounts/{uuid}/force-snapshot`  
**Authorization:** `Bearer {{APIKey}}`

**Description:** Manually triggers an immediate snapshot of account metrics (balance, equity, targets) for evaluation purposes.

---

#### POST /v1/prop/trading-accounts/{uuid}/failure-snapshot

Record a failure snapshot for a prop trading account.

**Method:** `POST`  
**Path:** `/v1/prop/trading-accounts/{uuid}/failure-snapshot`  
**Authorization:** `Bearer {{APIKey}}`

**Description:** Marks the account with a failure snapshot, recording the breach event and account state at failure time.

---

### Prop Targets

#### GET /v1/prop/targets

Retrieve all prop targets/rules.

**Method:** `GET`  
**Path:** `/v1/prop/targets`  
**Authorization:** `Bearer {{APIKey}}`

**Response:**
```json
[
  {
    "uuid": "string",
    "challengeUuid": "string",
    "type": "string",
    "value": 0,
    "description": "string"
  }
]
```

---

#### GET /v1/prop/targets/{uuid}

Retrieve a specific prop target by UUID.

**Method:** `GET`  
**Path:** `/v1/prop/targets/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

---

### Prop Payments

#### POST /v1/prop/payments/manual-withdrawal

Process a manual withdrawal for a prop account.

**Method:** `POST`  
**Path:** `/v1/prop/payments/manual-withdrawal`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "propTradingAccountUuid": "string",
  "amount": 0,
  "paymentGatewayUuid": "string",
  "comment": "string"
}
```

---

#### GET /v1/prop/payments/max-amount

Retrieve the maximum withdrawal amount for a prop account.

**Method:** `GET`  
**Path:** `/v1/prop/payments/max-amount`  
**Authorization:** `Bearer {{APIKey}}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| propTradingAccountUuid | string | Yes | Prop trading account UUID |

---

#### GET /v1/prop/payments/amount-for-deposit

Retrieve the amount available for deposit for a prop account.

**Method:** `GET`  
**Path:** `/v1/prop/payments/amount-for-deposit`  
**Authorization:** `Bearer {{APIKey}}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| propTradingAccountUuid | string | Yes | Prop trading account UUID |

---

### Prop Evaluation Requests

#### GET /v1/prop/evaluation-requests

Retrieve all prop evaluation requests.

**Method:** `GET`  
**Path:** `/v1/prop/evaluation-requests`  
**Authorization:** `Bearer {{APIKey}}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| page | integer | No | Page number |
| size | integer | No | Page size |
| status | string | No | Filter by status (PENDING, CONFIRMED, REJECTED) |

**Response:**
```json
{
  "content": [
    {
      "uuid": "string",
      "propTradingAccountUuid": "string",
      "accountUuid": "string",
      "status": "PENDING | CONFIRMED | REJECTED",
      "created": "string",
      "reviewed": "string",
      "reviewedByUuid": "string"
    }
  ],
  "totalElements": 0,
  "totalPages": 0
}
```

---

#### POST /v1/prop/evaluation-requests/{uuid}/confirm

Confirm a prop evaluation request.

**Method:** `POST`  
**Path:** `/v1/prop/evaluation-requests/{uuid}/confirm`  
**Authorization:** `Bearer {{APIKey}}`

**Description:** Confirms (approves) a pending evaluation request. Triggers the next phase of the challenge for the associated prop trading account.

---

#### POST /v1/prop/evaluation-requests/{uuid}/reject

Reject a prop evaluation request.

**Method:** `POST`  
**Path:** `/v1/prop/evaluation-requests/{uuid}/reject`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "reason": "string"
}
```

---

### Prop Competitions

#### GET /v1/prop/competitions

Retrieve all prop competitions (summary list).

**Method:** `GET`  
**Path:** `/v1/prop/competitions`  
**Authorization:** `Bearer {{APIKey}}`

---

#### GET /v1/prop/competitions/details

Retrieve detailed list of all competitions.

**Method:** `GET`  
**Path:** `/v1/prop/competitions/details`  
**Authorization:** `Bearer {{APIKey}}`

**Response:**
```json
[
  {
    "uuid": "string",
    "name": "string",
    "description": "string",
    "startDate": "string",
    "endDate": "string",
    "status": "ACTIVE | INACTIVE | COMPLETED",
    "offerUuid": "string",
    "initialBalance": 0,
    "currency": "string"
  }
]
```

---

#### GET /v1/prop/competitions/details/{uuid}

Retrieve a specific competition by UUID.

**Method:** `GET`  
**Path:** `/v1/prop/competitions/details/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

---

#### POST /v1/prop/competitions/details

Create a new competition.

**Method:** `POST`  
**Path:** `/v1/prop/competitions/details`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "name": "string",
  "description": "string",
  "startDate": "string",
  "endDate": "string",
  "offerUuid": "string",
  "initialBalance": 0,
  "currency": "string"
}
```

---

#### PUT /v1/prop/competitions/details/{uuid}

Update an existing competition.

**Method:** `PUT`  
**Path:** `/v1/prop/competitions/details/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:** Same fields as create (all optional for update)

---

### Competition Accounts

#### GET /v1/prop/competitions/competition-accounts

Retrieve all competition accounts.

**Method:** `GET`  
**Path:** `/v1/prop/competitions/competition-accounts`  
**Authorization:** `Bearer {{APIKey}}`

---

#### GET /v1/prop/competitions/{competitionUuid}/competition-accounts

Retrieve all accounts for a specific competition.

**Method:** `GET`  
**Path:** `/v1/prop/competitions/{competitionUuid}/competition-accounts`  
**Authorization:** `Bearer {{APIKey}}`

**Response:**
```json
[
  {
    "uuid": "string",
    "competitionUuid": "string",
    "accountUuid": "string",
    "login": "string",
    "systemUuid": "string",
    "rank": 0,
    "profit": 0,
    "balance": 0,
    "equity": 0,
    "status": "string"
  }
]
```

---

#### GET /v1/prop/competitions/competition-accounts/{uuid}

Retrieve a specific competition account by UUID.

**Method:** `GET`  
**Path:** `/v1/prop/competitions/competition-accounts/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

---

#### POST /v1/prop/competitions/{competitionUuid}/competition-accounts

Create a new competition account (register a trader for a competition).

**Method:** `POST`  
**Path:** `/v1/prop/competitions/{competitionUuid}/competition-accounts`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "accountUuid": "string",
  "offerUuid": "string"
}
```

---

#### PUT /v1/prop/competitions/competition-accounts/{uuid}

Update a competition account.

**Method:** `PUT`  
**Path:** `/v1/prop/competitions/competition-accounts/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "status": "string",
  "rank": 0
}
```

---

### Competition Targets

#### GET /v1/prop/competitions/{competitionUuid}/competition-targets

Retrieve targets/rules for a specific competition.

**Method:** `GET`  
**Path:** `/v1/prop/competitions/{competitionUuid}/competition-targets`  
**Authorization:** `Bearer {{APIKey}}`

**Response:**
```json
[
  {
    "uuid": "string",
    "competitionUuid": "string",
    "type": "string",
    "value": 0,
    "description": "string"
  }
]
```

---

#### GET /v1/prop/competitions/competition-targets/{uuid}

Retrieve a specific competition target by UUID.

**Method:** `GET`  
**Path:** `/v1/prop/competitions/competition-targets/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

---

### Competition Payments

#### POST /v1/prop/competitions/competition-accounts/{uuid}/deposit

Process a deposit for a competition account.

**Method:** `POST`  
**Path:** `/v1/prop/competitions/competition-accounts/{uuid}/deposit`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "amount": 0,
  "paymentGatewayUuid": "string",
  "comment": "string"
}
```

---

### Prop Add-Ons

#### GET /v1/prop/add-ons

Retrieve all prop add-ons.

**Method:** `GET`  
**Path:** `/v1/prop/add-ons`  
**Authorization:** `Bearer {{APIKey}}`

**Response:**
```json
[
  {
    "uuid": "string",
    "name": "string",
    "description": "string",
    "price": 0,
    "currency": "string",
    "type": "string",
    "status": "ACTIVE | INACTIVE"
  }
]
```

---

#### GET /v1/prop/add-ons/{uuid}

Retrieve a specific add-on by UUID.

**Method:** `GET`  
**Path:** `/v1/prop/add-ons/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

---

#### PUT /v1/prop/add-ons/{uuid}

Update a prop add-on.

**Method:** `PUT`  
**Path:** `/v1/prop/add-ons/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "name": "string",
  "description": "string",
  "price": 0,
  "status": "ACTIVE | INACTIVE"
}
```

---

#### POST /v1/prop/add-ons

Create a new prop add-on.

**Method:** `POST`  
**Path:** `/v1/prop/add-ons`  
**Authorization:** `Bearer {{APIKey}}`

**Request Body:**
```json
{
  "name": "string",
  "description": "string",
  "price": 0,
  "currency": "string",
  "type": "string"
}
```

---

#### DELETE /v1/prop/add-ons/{uuid}

Delete a prop add-on.

**Method:** `DELETE`  
**Path:** `/v1/prop/add-ons/{uuid}`  
**Authorization:** `Bearer {{APIKey}}`

---

## Social Trading

### GET /v1/social-trading/money-managers

Retrieve the money managers leaderboard.

**Method:** `GET`  
**Path:** `/v1/social-trading/money-managers`  
**Authorization:** `Bearer {{APIKey}}`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| systemUuid | string | Yes | Trading server UUID |
| page | integer | No | Page number |
| size | integer | No | Page size |

**Response:**
```json
[
  {
    "login": "string",
    "nickname": "string",
    "profit": 0,
    "investors": 0,
    "aum": 0,
    "winRate": 0,
    "rank": 0
  }
]
```

---

### GET /v1/social-trading/money-managers/{login}/statistics

Retrieve detailed statistics for a specific money manager.

**Method:** `GET`  
**Path:** `/v1/social-trading/money-managers/{login}/statistics`  
**Authorization:** `Bearer {{APIKey}}`

**Path Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| login | string | Yes | Money manager's trading login |

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| systemUuid | string | Yes | Trading server UUID |

**Response:**
```json
{
  "login": "string",
  "nickname": "string",
  "totalProfit": 0,
  "monthlyProfit": 0,
  "winRate": 0,
  "totalTrades": 0,
  "investors": 0,
  "aum": 0,
  "maxDrawdown": 0,
  "tradingHistory": []
}
```

---

## CRM Events

### Webhooks

CRM Events are delivered via webhooks. Configure your webhook endpoint to receive real-time



