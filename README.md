# EVM Payment Gateway for WooCommerce

A secure WordPress plugin enabling EVM-compatible token payments through WooCommerce using MetaMask.

## Features

- Support for any EVM-compatible blockchain (Ethereum, BSC, Polygon, etc.)
- MetaMask integration for secure transactions
- Configurable token contract settings
- Transaction verification and order status management
- Detailed payment logging and error handling

## Requirements

- WordPress 5.8 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher
- MetaMask browser extension

## Installation

1. Download the plugin
2. Upload to your WordPress site
3. Activate the plugin through WordPress admin
4. Configure the payment gateway settings in WooCommerce > Settings > Payments

## Configuration

1. Enable the payment gateway
2. Set your wallet address (recipient address)
3. Configure token contract details:
   - Contract address
   - ABI
   - Token decimals
4. Set blockchain network ID
5. Save changes

## Usage

1. Customers select "EVM Token Payment" at checkout
2. MetaMask prompts for connection
3. Customer confirms the transaction
4. Order is automatically updated upon successful payment

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test
