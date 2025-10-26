# User Cards Bridge

A comprehensive web service bridge plugin for managing user cards, roles, scheduling, and WooCommerce integration with SMS notifications.

## 🚀 Features

### Core Functionality
- **User Role Management**: Company managers, supervisors, and agents with proper access control
- **Card Management**: Full integration with user-cards plugin for service management
- **Form Submission**: Automatic form routing from main site to assigned supervisors
- **Customer Management**: Complete customer lifecycle with status tracking
- **Scheduling System**: Advanced capacity management and reservation system
- **WooCommerce Integration**: Seamless upsell orders and payment processing
- **SMS Integration**: Payamak Panel integration for automated notifications
- **REST API**: Complete API for external panel integration
- **Security**: JWT authentication and role-based access control
- **Wallet & Coupon Forwarding**: Push wallet codes or WooCommerce coupons to destination stores with SMS confirmations

### Business Logic
- **Automatic Form Assignment**: Forms are automatically assigned to card supervisors
- **Status Management**: Complete customer status workflow with automatic actions
- **Upsell System**: Integrated payment processing with SMS notifications
- **Capacity Management**: Real-time availability tracking and reservation system
- **Multi-role Dashboard**: Different interfaces for managers, supervisors, and agents

## 📋 Requirements

- WordPress 5.0+
- PHP 8.1+
- WooCommerce (for payment processing)
- user-cards plugin (for card management)

## 🔧 Installation

1. Upload the plugin files to `/wp-content/plugins/user-cards-bridge/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure the plugin settings in the admin panel
4. Set up SMS credentials and API settings
5. Assign supervisors to cards
6. Configure user roles and permissions

## ⚙️ Configuration

### SMS Settings
1. Go to User Cards Bridge > Settings
2. Enter your Payamak Panel credentials
3. Set up Body IDs for different message types:
   - Normal status code messages
   - Upsell payment messages
   - Coupon code messages
   - Wallet code messages
4. Test the configuration

### API Settings
1. Configure destination authentication (API key or JWT)
2. Optionally define an HMAC secret for signed requests
3. Configure coupon defaults (WooCommerce REST credentials or generic endpoint, usage limits, expiry days)
4. Set the wallet endpoint path for the Woo Wallet Bridge plugin and default wallet expiry
5. Configure CORS allowed origins for external panel
6. Set payment token expiry time (1-168 hours)
7. Configure log retention period (1-365 days)

### Security Settings
1. Set webhook secret for secure webhook handling
2. Configure allowed origins for CORS requests
3. Review user role permissions

### Customer Statuses
1. Configure custom customer statuses
2. Set up status transition rules
3. Define automatic actions for each status

## 🔌 API Documentation

### Base URL
```
/wp-json/user-cards-bridge/v1/
```

### Authentication
All API requests (except public endpoints) require JWT authentication:
```
Authorization: Bearer YOUR_JWT_TOKEN
```

### Main Endpoints

#### Authentication
- `POST /auth/login` - Login and get JWT token
- `POST /auth/register` - Register new user
- `POST /auth/refresh` - Refresh JWT token
- `POST /auth/logout` - Logout

#### Users & Roles
- `GET /managers` - Get managers
- `GET /supervisors` - Get supervisors
- `GET /agents` - Get agents
- `POST /agents` - Create agent
- `PATCH /agents/{id}/supervisor` - Update agent supervisor
- `POST /supervisors/{id}/cards` - Assign cards to supervisor

#### Customers
- `GET /customers` - Get customers with filters
- `GET /customers/{id}` - Get single customer
- `PATCH /customers/{id}/status` - Update customer status
- `POST /customers/{id}/notes` - Add customer note
- `POST /customers/{id}/assign-supervisor` - Assign supervisor
- `POST /customers/{id}/assign-agent` - Assign agent

#### Cards & Forms
- `GET /cards` - Get cards
- `GET /cards/{id}/fields` - Get card fields for upsell
- `GET /supervisors/{id}/cards` - Get supervisor's cards
- `POST /forms/submit` - Submit form from main site
- `GET /supervisors/{id}/forms` - Get supervisor's forms

#### Schedule & Reservations
- `GET /schedule/{supervisor_id}/{card_id}` - Get schedule matrix
- `PUT /schedule/{supervisor_id}/{card_id}` - Update schedule matrix
- `GET /availability/{card_id}` - Get available slots
- `POST /reservations` - Create reservation
- `GET /reservations` - Get reservations

#### Upsell, Wallet & SMS
- `POST /customers/{id}/upsell/init` - Initialize upsell process
- `POST /customers/{id}/normal/send-code` - Send normal status code
- `POST /sms/send` - Send SMS
- `POST /codes` - Forward wallet or coupon codes to destination store

#### Statistics
- `GET /stats/dashboard` - Get dashboard statistics
- `GET /stats/sms` - Get SMS statistics
- `GET /stats/status` - Get status statistics

## 🏗️ Architecture

### User Roles
- **Company Manager**: Full access to all features and data
- **Supervisor**: Manages assigned cards, agents, and customers
- **Agent**: Manages assigned customers and status updates

### Database Structure
- Custom tables for capacity slots, reservations, and logs
- User meta for customer data and assignments
- Post meta for card configurations

### Integration Points
- **user-cards**: Card data and field management
- **WooCommerce**: Order creation and payment processing
- **Payamak Panel**: SMS notifications via SOAP API

## 🔄 Business Workflow

### Form Submission Flow
1. Customer submits form on main site
2. Form is automatically assigned to card supervisor
3. Supervisor receives notification
4. Supervisor can assign agent to customer
5. Agent manages customer relationship

### Status Management Flow
1. Customer status can be changed by authorized users
2. Status changes trigger automatic actions:
   - `normal`: Generate and send random code
   - `upsell`: Initiate payment process
   - `upsell_pending`: Wait for payment
   - `upsell_paid`: Complete transaction

### Upsell Process
1. Agent changes customer status to `upsell`
2. System fetches card fields with pricing
3. Agent selects field and initiates upsell
4. WooCommerce order is created
5. Payment link is generated
6. SMS is sent to customer
7. Status changes to `upsell_pending`
8. Payment webhook updates status to `upsell_paid`

## 🛡️ Security Features

- JWT token authentication
- Role-based access control
- CORS protection
- Input validation and sanitization
- Rate limiting
- Webhook signature verification
- Secure payment token system

## 📊 Monitoring & Logging

- Comprehensive activity logging
- SMS delivery tracking
- Status change history
- Error logging and monitoring
- Performance metrics

## 🔧 Troubleshooting

### Common Issues
1. **JWT Authentication Errors**: Check token validity and expiration
2. **SMS Not Sending**: Verify Payamak Panel credentials
3. **Form Not Assigning**: Check card supervisor assignment
4. **Payment Issues**: Verify WooCommerce configuration

### Debug Mode
Enable debug logging in plugin settings for detailed error information.

## 📈 Performance

- Caching for frequently accessed data
- Optimized database queries
- Efficient API responses
- Background processing for heavy operations

## 🔄 Updates & Maintenance

- Automatic log cleanup
- Database optimization
- Security updates
- Feature enhancements

## 📞 Support

For support and documentation:
1. Check the plugin settings page
2. Review API documentation
3. Check error logs
4. Contact the developer

## 📝 Changelog

### 1.0.0
- Initial release
- Complete API implementation
- SMS integration with Payamak Panel
- WooCommerce integration
- Role-based access control
- Form submission system
- Status management workflow
- Scheduling and capacity management
- Comprehensive logging system
- Admin dashboard for all roles
- Complete settings configuration