(function($) {
    'use strict';

    class EVMPayment {
        constructor() {
            this.web3 = null;
            this.isInitialized = false;
            this.initializeEventListeners();
        }

        async initializeWeb3() {
            try {
                if (!window.ethereum) {
                    throw new Error('MetaMask not detected! Please install MetaMask first.');
                }

                this.web3 = new Web3(window.ethereum);
                await window.ethereum.request({ method: 'eth_requestAccounts' });
                this.isInitialized = true;

                // Setup network change listener
                window.ethereum.on('chainChanged', () => {
                    window.location.reload();
                });

                // Setup account change listener
                window.ethereum.on('accountsChanged', () => {
                    window.location.reload();
                });

                return true;
            } catch (error) {
                this.showError(error.message);
                return false;
            }
        }

        async validateNetwork() {
            try {
                const networkId = await this.web3.eth.net.getId();
                if (String(networkId) !== evmPaymentData.networkId) {
                    throw new Error(`Please switch to network ${evmPaymentData.networkId}`);
                }
                return true;
            } catch (error) {
                this.showError(error.message);
                return false;
            }
        }

        async requestPayment(amount) {
            try {
                if (!this.isInitialized && !await this.initializeWeb3()) {
                    return;
                }

                if (!await this.validateNetwork()) {
                    return;
                }

                const contract = new this.web3.eth.Contract(
                    evmPaymentData.abiArray,
                    evmPaymentData.contractAddress
                );

                const accounts = await this.web3.eth.getAccounts();
                const tokenAmount = this.calculateTokenAmount(amount);

                console.log('Payment details:', {
                    from: accounts[0],
                    to: evmPaymentData.targetAddress,
                    amount: tokenAmount,
                    decimals: evmPaymentData.tokenDecimals
                });

                const result = await contract.methods.transfer(
                    evmPaymentData.targetAddress,
                    tokenAmount
                ).send({
                    from: accounts[0]
                });

                console.log('Transaction result:', result);
                await this.verifyPayment(result.transactionHash);

            } catch (error) {
                if (error.code === 4001) {
                    this.showError('Transaction was rejected by user.');
                } else {
                    this.showError(error.message);
                }
                console.error('Payment error:', error);
            }
        }

        calculateTokenAmount(amount) {
            try {
                const decimals = parseInt(evmPaymentData.tokenDecimals);
                const multiplier = new this.web3.utils.BN(10).pow(new this.web3.utils.BN(decimals));
                const rawAmount = new this.web3.utils.BN(Math.round(amount * 100)).mul(multiplier).div(new this.web3.utils.BN(100));
                return rawAmount.toString();
            } catch (error) {
                console.error('Calculate amount error:', error);
                throw new Error('Error calculating token amount');
            }
        }

        async verifyPayment(txHash) {
            try {
                const data = new FormData();
                data.append('action', 'verify_evm_payment');
                data.append('nonce', evmPaymentData.nonce);
                data.append('order_id', evmPaymentConfig.orderId);
                data.append('tx', txHash);

                console.log('Verifying payment:', {
                    txHash,
                    orderId: evmPaymentConfig.orderId
                });

                const response = await fetch(evmPaymentData.ajaxUrl, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();
                console.log('Verification response:', result);

                if (result.success) {
                    this.showSuccess('Payment successful! Redirecting...');
                    setTimeout(() => {
                        window.location.href = result.data.redirect;
                    }, 2000);
                } else {
                    throw new Error(result.data.message || 'Payment verification failed');
                }
            } catch (error) {
                this.showError(`Payment verification failed: ${error.message}`);
                console.error('Verification error:', error);
            }
        }

        showError(message) {
            const errorDiv = document.getElementById('evm-payment-error');
            if (errorDiv) {
                errorDiv.textContent = message;
                errorDiv.style.display = 'block';
            }
        }

        showSuccess(message) {
            const errorDiv = document.getElementById('evm-payment-error');
            if (errorDiv) {
                errorDiv.textContent = message;
                errorDiv.style.display = 'block';
                errorDiv.classList.remove('woocommerce-error');
                errorDiv.classList.add('woocommerce-message');
            }
        }

        initializeEventListeners() {
            document.addEventListener('DOMContentLoaded', () => {
                this.initializeWeb3().catch(console.error);
            });
        }
    }

    // Initialize and expose to window
    window.evmPayment = new EVMPayment();

})(jQuery);
