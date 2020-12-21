Vue.component('user-security', {
	props: ['id'],
	template: `<b-card>
		<modal-change-password :id="id"></modal-change-password>
		<modal-two-step-verification :id="id" @success="sync"></modal-two-step-verification>
		<b-row>
			<b-col lg="6">
				<h2 class="h3">Password</h2>
				<b-card>
					<b-row>
						<b-col>
							<div><span>*******</span></div>
							<div v-if="lastChangedPassword" class="text-secondary">Last changed {{ lastChangedPassword }}</div>
						</b-col>
						<b-col cols="2">
							<b-button variant="secondary" v-b-modal.modal-change-password>Change</b-button>
						</b-col>
					</b-row>
				</b-card>
			</b-col>
			<b-col lg="6">
				<h2 class="h3">Two-step verification</h2>
				<b-spinner v-if="isOAuth === null"></b-spinner>
				<b-alert v-else variant="danger" :show="!isOAuth">
					<p>Two-step verification is not active.</p>
					<b-btn v-if="!isOAuth" variant="secondary" title="Set two-step verification" v-b-modal.modal-two-step-verification>
						Set verification
					</b-btn>
				</b-alert>
				<b-btn v-if="isOAuth" variant="danger" size="sm" class="mt-4" title="Disable two-step verification" v-b-modal.disable-auth>
					Disable Verification
				</b-btn>
			</b-col>
		</b-row>
		<b-modal id="disable-auth" title="Disable Two-Step Verification">
			<p>Are you sure you want to remove Two-Step Verification?</p>
			<template v-slot:modal-footer>
				<b-btn size="sm" variant="white" @click="$bvModal.hide('disable-auth')">Close</b-btn>
				<b-btn v-if="!isDisabling" size="sm" variant="danger" @click="disableAuth">Disable</b-btn>
				<b-btn v-else size="sm" variant="danger" disabled>Disabling</b-btn>
			</template>
		</b-modal>
	</b-card>`,
	data() {
		return {
			lastChangedPassword: null,
			isOAuth: null,
			isDisabling: false
		}
	},
	mounted() {
		this.sync();
	},
	methods: {
		disableAuth() {
			this.isDisabling = true;
			axiosApi.post('user/cancel-oauth', {
				id: this.id
			}).then(req => {
				this.$bvModal.hide('disable-auth');
				this.sync();
			}).finally(() => this.isDisabling = false)
		},
		sync() {
			axiosApi.get(`user/security?id=${this.id}`)
				.then(req => {
					this.lastChangedPassword = req.data.lastChangedPassword;
					this.isOAuth = req.data.twoFactorAuth;
				})
		}
	}
});

Vue.component('modal-change-password', {
	props: ['id'],
	template: `<div>
	<b-modal id="modal-change-password" title="Change password" hide-footer>
		<modal-generate-password></modal-generate-password>
		<p>Choose a&nbsp;strong password and don't reuse it for other accounts.</p>
		<b-card class="bg-light mb-3">
			<b-form autocomplete="off" ref="passForm">
				<b-form-group label="New password">
					<b-input-group>
						<b-input-group-prepend is-text>
							<b-icon :icon="isShowing ? 'eye-slash' : 'eye'" @click="isShowing = !isShowing" v-b-tooltip.hover title="Display the password in a readable form."></b-icon>
						</b-input-group-prepend>
						<b-form-input v-model="form.password" minlength="6" :type="isShowing ? 'text' : 'password'" @input="checkSame()" required></b-form-input>
						<b-input-group-append>
							<b-button variant="secondary" v-b-modal.modal-generate-password>Generate</b-button>
						</b-input-group-append>
						<div class="invalid-feedback">
							The password must be&nbsp;at&nbsp;least 6&nbsp;characters long
						</div>
					</b-input-group>
				</b-form-group>
				<b-form-group label="Confirm new password">
					<b-form-input v-model="form.repeatPassword" :type="isShowing ? 'text' : 'password'" @input="checkSame()" :class="isSame ? 'is-valid' : form.repeatPassword.length == 0 ? '' : 'is-invalid' " required></b-form-input>
					<div class="invalid-feedback">
						Passwords doesn't match each other
					</div>
				</b-form-group>
				<b-button variant="primary" @click="savePassword">Change password now</b-button>
			</b-form>
		</b-card>
		<p class="text-secondary">
			Use at least 8&nbsp;characters. Don’t use a&nbsp;password from another site,
			or&nbsp;something too obvious like your pet’s name.
		</p>
	</b-modal>
</div>`,
	mounted() {
		eventBus.$on('set-password', (password) => {
			this.form.password = password;
			this.form.repeatPassword = password;
			this.checkSame();
		});
	},
	data() {
		return {
			isSame: null,
			isShowing: false,
			form: {
				password: '',
				repeatPassword: '',
			}
		}
	},
	methods: {
		savePassword() {
			let form = this.$refs.passForm;
			if (!form.checkValidity()) {
				form.classList.add('was-validated');
			}
			if (form.checkValidity() && this.isSame === true) {
				axiosApi.post('user/set-user-password', {
					id: this.id,
					password: this.form.password,
				}).then(req => {
					this.$bvModal.hide('modal-change-password');
				});
			}
		},
		checkSame() {
			this.isSame = this.form.password === this.form.repeatPassword;
		}
	}
});

Vue.component('modal-generate-password', {
	template: `<b-modal id="modal-generate-password" title="Random secure password generator" @shown="generatePassword()" hide-footer>
		<table class="table">
			<tr v-for="(password, label) in passwords">
				<td style="width:100px">{{ label | firstUpper }}</td>
				<td>
					<template v-if="isFetching">
						<b-spinner small></b-spinner>
					</template>
					<code v-else>{{ password }}</code>
				</td>
				<td style="width:100px">
					<b-button class="btn-success btn-sm py-0 w-100" @click="setPassword(password)">
						Use it
					</b-button>
				</td>
			</tr>
		</table>
		<div class="text-right">
			<b-button size="sm" class="mb-2" @click="generatePassword()">
				<b-icon icon="arrow-clockwise" aria-hidden="true"></b-icon> Regenerate
			</b-button>
		</div>
	</b-modal>`,
	data() {
		return {
			isFetching: true,
			passwords: {
				numbers: '',
				simple: '',
				normal: '',
				advance: ''
			}
		}
	},
	methods: {
		setPassword(password) {
			eventBus.$emit('set-password', password);
			this.$bvModal.hide('modal-generate-password');
		},
		generatePassword() {
			this.isFetching = true;
			axiosApi.get('user/random-password')
				.then(req => {
					this.passwords = req.data;
					this.isFetching = false;
				})
		}
	},
	filters: {
		firstUpper: function (value) {
			if (!value) return '';
			value = value.toString();
			return value.charAt(0).toUpperCase() + value.slice(1);
		}
	}
});

Vue.component('modal-two-step-verification', {
	props: ['id'],
	template: `<div>
		<b-modal id="modal-two-step-verification" title="Two-step verification" @shown="fetchQR" hide-footer>
			<div v-if="loading.global" class="text-center py-5">
				<b-spinner></b-spinner>
			</div>
			<template v-else>
				<b-button variant="secondary" @click="showInstructions=!showInstructions" class="btn-sm mb-3">
					{{ showInstructions ? 'Hide instructions' : 'Show instructions' }}
				</b-button>
				<b-card v-if="showInstructions" class="mb-3">
					<h3 class="h5">Setup instructions:</h3>
					<p>
						In a mobile application
						(such as <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2&amp;hl=en" target="_blank"
						>Google Authenticator</a>),
						load the generated QR code or copy the generated data manually.
					</p>
					<p>
						If everything works, verify the generated code in the form.
						If the verification is successful, the settings will be saved automatically.
					</p>
				</b-card>
				<b-card class="bg-light border rounded">
					<h4 class="h5">Manual registration</h4>
					Account: <code>{{ twoFactor.account }}</code> <br>
					Key: <code>{{ twoFactor.otpCode.human }}</code>
				</b-card>
				<div class="d-flex align-items-center pt-1 my-3">
					<span class="mr-3">Either load QR code or type parameters above manually.</span>
					
				</div>
				<div v-if="twoFactor.qrCodeUrl" class="text-center">
					<img :src="twoFactor.qrCodeUrl" @load="loading.qrCode=false" :width="loading.qrCode ? 1 : 200" :height="loading.qrCode ? 1 : 200" alt="QR code" style="image-rendering:-moz-crisp-edges;image-rendering:-o-crisp-edges;image-rendering:-webkit-optimize-contrast;image-rendering:pixelated;-ms-interpolation-mode:nearest-neighbor">
					<b-skeleton-img v-if="loading.qrCode" no-aspect height="200px" class="m-auto"></b-skeleton-img>
				</div>
				<div class="d-flex">
					<b-btn variant="secondary" class="mx-auto mt-3" size="sm" @click="verified = !verified">Continue to Verification</b-btn>
				</div>
				<b-form-group v-show="verified" label="For verification type the generated code" class="mt-3">
					<form ref="codeForm">
						<b-input-group>
							<input type="text" v-mask="codeMask" v-model="codeValue" maxlength="7" minlength="7" placeholder="XXX XXX" required :class="['form-control', isValid ?  'is-valid' : codeValue === null ? '' : 'is-invalid']">
							<b-input-group-append>
								<b-button variant="primary" @click="verifyCode">Verify</b-button>
							</b-input-group-append>
							<p class="invalid-feedback">Must be 6 characters long</p>
						</b-input-group>
					</form>
					<b-progress :max="30" variant="warning" :animated="true" class="mt-1">
						<b-progress-bar :value="progressValue">
							{{ progressValue }} sec
						</b-progress-bar>
					</b-progress>
				</b-form-group>
			</template>
		</b-modal>
	</div>`,
	data() {
		return {
			verified: false,
			progressValue: 0,
			codeMask: '### ###',
			codeValue: null,
			isValid: false,
			showInstructions: false,
			loading: {
				global: true,
				qrCode: true,
			},
			twoFactor: {
				account: null,
				otpCode: {},
				qrCodeUrl: null,
			}
		}
	},
	mounted() {
		setInterval(() => {
			this.progressValue = 30 - (new Date().getSeconds() % 30)
		}, 900)
	},
	watch: {
		codeValue(newVal, old) {
			this.isValid = newVal.length === 7
		}
	},
	methods: {
		verifyCode() {
			let codeForm = this.$refs.codeForm;
			console.log(codeForm.checkValidity());
			if (!codeForm.checkValidity()) {
				codeForm.classList.add('was-validated');
				return;
			} else {
				axiosApi.post('user/set-auth', {
					id: this.id,
					hash: this.twoFactor.otpCode.hash,
					code: this.codeValue.replace(' ', ''),
				}).then(req => {
					this.$bvModal.hide('modal-two-step-verification');
					this.$emit('success')
				}).catch(req => {
					this.codeValue = null;
					this.isValid = false;
				})
			}
		},
		fetchQR() {
			this.loading.global = true;
			this.loading.qrCode = true;
			axiosApi.get(`user/generate-oauth?id=${this.id}`)
				.then(req => {
					this.loading.global = false;
					this.twoFactor = req.data;
				})
		},
		qrLoaded() {
			alert(1);
		}
	}
});
