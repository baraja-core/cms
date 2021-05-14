Vue.component('user-overview', {
	props: ['id'],
	template: `<div>
		<b-card>
			<div v-if="loading.init" class="text-center my-5">
				<b-spinner></b-spinner>
			</div>
			<template v-else>
				<b-row>
					<b-col cols="1">
						<div>
							<img :src="avatarUrl + '?' + avatarUrlRandom" :alt="'User ' + form.username" class="w-100">
						</div>
						<div class="mt-3">
							<b-button variant="secondary" class="btn btn-sm py-0" v-b-modal.modal-change-photo>Change photo</b-button>
						</div>
					</b-col>
					<b-col>
						<form ref="form">
							<b-row>
								<b-col lg="6">
									<b-form-group label="Full name">
										<b-form-input v-model="form.fullName" type="text" trim required></b-form-input>
									</b-form-group>
								</b-col>
								<b-col lg="6">
									<b-form-group label="Username">
										<b-form-input v-model="form.username" type="text" trim required></b-form-input>
									</b-form-group>
								</b-col>
							</b-row>
							<b-row>
								<b-col lg="6">
									<b-form-group label="Primary e-mail">
										<b-form-input v-model="form.email" type="email" trim required></b-form-input>
									</b-form-group>
								</b-col>
								<b-col lg="6">
									<b-form-group label="Phone">
										<div class="card px-3">
											<div class="row">
												<div class="col py-2">
													<template v-if="form.phone.original">
														{{ form.phone.original }}
													</template>
													<template v-else>
														<i class="text-secondary">Phone is not set</i>
													</template>
												</div>
												<div class="col-2 py-1 text-right">
													<b-button variant="outline-secondary" class="btn-sm py-1" v-b-modal.modal-change-phone>
														<b-icon icon="pencil"></b-icon>
													</b-button>
												</div>
											</div>
										</div>
									</b-form-group>
								</b-col>
								<b-col lg="6">
									<b-form-group label="Created">
										<b-form-input disabled v-model="created"></b-form-input>
									</b-form-group>
								</b-col>
								<b-col lg="6">
									<b-form-group label="Save this form:">
										<b-btn variant="primary" v-if="!loading.saving" @click="saveData()">Save</b-btn>
										<b-btn variant="primary" v-else disabled><b-spinner class="mr-2" small></b-spinner>Save</b-btn>
									</b-form-group>
								</b-col>
							</b-row>
						</form>
						<div v-if="loading.meta" class="text-center py-5">
							<b-spinner></b-spinner>
						</div>
						<template v-else>
							<b-row v-if="meta.length !== 0">
								<b-col>
									<h3 class="h4 mt-3">Meta data:</h3>
									<table class="table table-sm cms-table-no-border-top">
										<tr>
											<th>Key</th>
											<th>Value</th>
											<th></th>
										</tr>
										<tr v-for="(metaValue, metaKey) in meta">
											<td>{{ metaKey }}</td>
											<td>
												<template v-if="typeof metaValue === 'string' || metaValue instanceof String">
													{{ metaValue }}
												</template>
												<template v-else>
													<span v-if="metaValue === null" class="badge badge-warning">null</span>
													<span v-else class="badge badge-danger">{{ typeof metaValue }}</span>
													<code>{{ metaValue }}</code>
												</template>
											</td>
											<td class="text-right">
												<b-button variant="success" class="btn btn-sm" @click="editMeta={ key: metaKey, value: metaValue }" v-b-modal.modal-change-meta>
													<b-icon icon="pencil" font-scale="1"></b-icon>
												</b-button>
												<b-button variant="danger" class="btn btn-sm" @click="deleteMetaValue(metaKey)">
													<b-icon icon="trash" font-scale="1"></b-icon>
												</b-button>
											</td>
										</tr>
									</table>
								</b-col>
							</b-row>
						</template>
					</b-col>
				</b-row>
			</template>
			<b-modal id="modal-change-photo" title="Change photo" size="lg" hide-footer>
				<b-form @submit="uploadPhoto">
					<b-row>
						<b-col cols="3">
							<img :src="avatarUrl + '?' + avatarUrlRandom" alt="Avatar" title="Avatar" class="w-100">
						</b-col>
						<b-col>
							<p class="text-secondary">A&nbsp;photo helps personalize your account.</p>
							<b-form-file v-model="editPhoto.file" accept="image/*"></b-form-file>
							<b-button variant="primary" type="submit" class="my-3">
								<template v-if="editPhoto.loading">
									<b-spinner small></b-spinner>
								</template>
								<template v-else>
									Upload photo
								</template>
							</b-button>
							<p class="text-secondary">Your profile photo is visible to everyone, across Baraja products.</p>
						</b-col>
					</b-form>
				</b-row>
			</b-modal>
			<b-modal id="modal-change-meta" title="Change meta value" hide-footer>
				Key:
				<b-form-input disabled v-model="editMeta.key"></b-form-input>
				<div class="mt-3">Value:</div>
				<b-form-input v-model="editMeta.value"></b-form-input>
				<b-button :variant="editMeta.value ? 'primary' : 'danger'" class="mt-3" @click="saveMetaValue">
					{{ editMeta.value ? 'Save new value' : 'Delete this key' }}
				</b-button>
				<p class="text-secondary mt-3">
					Meta information is used for technical configuration of the user account.
					Deleting the value input will remove this meta information.
				</p>
			</b-modal>
			<b-modal id="modal-change-phone" title="Change phone" hide-footer @shown="initPhone">
				<p class="text-secondary">
					Your number can be used to deliver important notifications, help you sign in, and more.
				</p>
				<div class="row">
					<div class="col-3">
						Region:
						<b-form-input type="number" v-model="editPhone.region"></b-form-input>
					</div>
					<div class="col">
						National number:
						<b-form-input v-model="editPhone.phone"></b-form-input>
					</div>
				</div>
				<b-button variant="primary" class="mt-3" @click="savePhone">Save phone</b-button>
			</b-modal>
		</b-card>
	</div>`,
	data() {
		return {
			loading: {
				init: true,
				saving: false,
				meta: false
			},
			meta: {},
			editMeta: {
				key: '',
				value: ''
			},
			editPhoto: {
				file: null,
				loading: false
			},
			editPhone: {
				region: '',
				phone: ''
			},
			created: '',
			avatarUrl: null,
			avatarUrlRandom: '',
			form: {
				fullName: null,
				email: null,
				username: null,
				phone: null,
				avatarUrl: null
			}
		}
	},
	mounted() {
		this.sync();
	},
	methods: {
		saveData() {
			let form = this.$refs.form;
			if (!form.checkValidity()) {
				form.classList.add('was-validated');
			} else {
				this.loading.saving = true;
				axiosApi.post('user/overview', {
					id: this.id,
					username: this.form.username,
					email: this.form.email,
					name: this.form.fullName
				}).then(req => {
					this.sync();
				}).finally(() => this.loading.saving = false)
			}
		},
		sync() {
			axiosApi.get(`user/overview?id=${this.id}`).then(req => {
				this.avatarUrlRandom = Math.random();
				this.form = req.data.form;
				this.created = req.data.created;
				this.avatarUrl = req.data.avatarUrl;
				this.meta = req.data.meta;
				this.loading.init = false;
				this.loading.meta = false;
			});
		},
		saveMetaValue() {
			this.loading.meta = true;
			axiosApi.get(`user/save-meta?id=${this.id}&key=${this.editMeta.key}&value=${this.editMeta.value}`).then(req => {
				this.$bvModal.hide('modal-change-meta');
				this.sync();
			});
		},
		deleteMetaValue(key) {
			if (confirm('Really delete "' + key + '"?')) {
				this.loading.meta = true;
				axiosApi.get(`user/save-meta?id=${this.id}&key=${key}`).then(req => {
					this.sync();
				});
			}
		},
		uploadPhoto(evt) {
			evt.preventDefault();
			this.editPhoto.loading = true;
			let formData = new FormData();
			formData.append('userId', this.id);
			formData.append('avatar', this.editPhoto.file);
			axiosApi.post('user/upload-avatar', formData, {
				headers: {
					'Content-Type': 'multipart/form-data'
				}
			}).then(req => {
				this.editPhoto.loading = false;
				this.$bvModal.hide('modal-change-photo');
				this.sync();
			});
		},
		initPhone() {
			this.editPhone.region = this.form.phone.region;
			this.editPhone.phone = this.form.phone.phone;
		},
		savePhone() {
			axiosApi.get(`user/save-phone?id=${this.id}&phone=${this.editPhone.phone}&region=${this.editPhone.region}`).then(req => {
				this.$bvModal.hide('modal-change-phone');
				this.sync();
			});
		}
	}
});
