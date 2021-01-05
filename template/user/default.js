Vue.component('user-default', {
	template: `<div class="my-3" title="User manager" :breadcrumb="breadcrumb" :buttons="buttons">
		<b-row>
			<b-col>
				<h1>User manager</h1>
			</b-col>
			<b-col cols="9" class="text-right">
				<!-- TODO
				<cms-select label="All users" :values="[{'value': 'Role', 'text': 'abcd'}]"></cms-select>
				<cms-select label="All roles" :values="roles"></cms-select>
				<cms-search placeholder="Search name or data..." @value="processSearch"></cms-search>
				-->
				<b-button variant="primary" class="btn-add" v-b-modal.modal-user-create>New user</b-button>
			</b-col>
		</b-row>
		<b-modal id="modal-user-create" title="Create user">
			<b-form ref="form" autocomplete="off">
				<b-form-group>
					<template v-slot:label>
						Full name <span class="text-danger">*</span>
					</template>
					<b-form-input v-model="user.form.fullName" required autocomplete="off" pattern="^([a-zA-ZÀ-ž]+[\'\,\.\-]?[a-zA-ZÀ-ž ]*)+[ ]([a-zA-ZÀ-ž]+[\'\,\.\-]?[a-zA-ZÀ-ž ]+)+$" trim></b-form-input>
					<div class="invalid-feedback">
						{{ errors.fullName }}
					</div>
				</b-form-group>
				<b-form-group>
					<template v-slot:label>
						E-mail <span class="text-danger">*</span>
					</template>
					<b-form-input v-model="user.form.email" :class="[errors.isEmailValid ? '' : 'is-invalid']" @input="checkMailDuplication()" required autocomplete="off" pattern="^([a-zA-Z0-9_\\-\\.]+)@((\\[[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.)|(([a-zA-Z0-9\\-]+\\.)+))([a-zA-Z]{2,4}|[0-9]{1,3})(\\]?)$" type="email" trim></b-form-input>
					<div class="invalid-feedback">
						{{ errors.email }}
					</div>
				</b-form-group>
				<b-form-group>
					<template v-slot:label>
						Role <span class="text-danger">*</span>
					</template>
					<b-form-select v-model="user.form.role" required :options="rolesSimple"></b-form-select>
				</b-form-group>
				<div class="row">
					<div class="col-6">Password</div>
					<div class="col-6 text-right">
						<button v-if="!isInputPasswordOpen" class="btn btn-secondary btn-sm" @click="isInputPasswordOpen=true">Set user password</button>
						<button v-else @click="[isInputPasswordOpen = false, user.form.password = null]" href="#" class="btn btn-outline-danger btn-sm">
							<b-icon icon="x"></b-icon>
							Ask user for password
						</button>
					</div>
				</div>
				<b-form-group v-if="isInputPasswordOpen" class="mt-3">
					<b-form-input v-model="user.form.password" type="password" @keydown.space.prevent required></b-form-input>
				</b-form-group>
				<div v-else class="text-secondary mt-2">
					<i>
						We will not set a&nbsp;password for the user and send him an e-mail
						with a&nbsp;link to set the first password.
					</i>
				</div>
			</b-form>
			<template v-slot:modal-footer>
				<b-btn size="sm" variant="white" @click="$bvModal.hide('modal-user-create')">Close</b-btn>
				<b-btn size="sm" variant="primary" @click="createUser()">
					<template v-if="isUserCreating">
						<b-spinner small class="mx-2"></b-spinner>
					</template>
					<template v-else>
						Create
					</template>
				</b-btn>
			</template>
		</b-modal>
		<b-row>
			<b-col cols="8" class="mb-3">
				<b-nav pills>
					<b-nav-item class="p-0" :active="search.status.label === null" @click="search.status.label = null; sync();">
						<span>All</span>
						<span v-if="user.statusCount" class="small">({{ user.statusCount.all }})</span>
					</b-nav-item>
					<b-nav-item class="p-0" :active="search.status.label === 'active'" @click="search.status.label = 'active'; sync();">
						<span>Active</span>
						<span v-if="user.statusCount" class="small">({{ user.statusCount.active }})</span>
					</b-nav-item>
					<b-nav-item class="p-0" :active="search.status.label === 'deleted'" @click="search.status.label = 'deleted'; sync();">
						<span>Deleted</span>
						<span v-if="user.statusCount" class="small">({{ user.statusCount.deleted }})</span>
					</b-nav-item>
				</b-nav>
			</b-col>
			<b-col cols="4" class="d-flex justify-content-end align-items-center pr-4 mb-1">
				<span class="text-muted small">Items found: <b>{{ paginator.itemCount }}</b></span>
			</b-col>
		</b-row>
		<cms-filter>
			<b-form inline class="w-100">
				<div class="w-100">
					<div class="d-flex flex-column flex-sm-row align-items-sm-center pr-lg-0">
						<b-form-input size="sm" v-model="search.name" @input="sync" class="mr-3" placeholder="Search users..."></b-form-input>
						<b-form-select size="sm" :options="roles" v-model="search.role" @change="sync"></b-form-select>
					</div>
				</div>
			</b-form>
		</cms-filter>
		<b-card>
			<b-alert :show="isCurrentUserUsing2fa === false" variant="warning">
				<h4 class="alert-heading">Security warning for your account!</h4>
				<p><b>Your user account does not use 2-step authentication.</b></p>
				<p>
					If an attacker reveals your password, he can impersonate you.
					If you set up 2-step login authentication, an attacker will still need to obtain your cell phone.
				</p>
			</b-alert>
			<table class="table table-sm cms-table-no-border-top">
				<tr>
					<th>Full name</th>
					<th>E-mail</th>
					<th>Role</th>
					<th>Status</th>
					<th class="text-right">Actions</th>
				</tr>
				<tr v-for="(item, offset) of user.items">
					<td>
						<a :href="link('User:detail', {id: item.id})">
							<img v-if="item.avatarUrl" :src="item.avatarUrl" :alt="item.name" style="max-height:32px">
							{{ item.name }}
						</a>
						<div v-if="item['2fa']" class="badge badge-primary" v-b-tooltip title="This user is using 2-step login authentication (better security).">2FA</div>
					</td>
					<td>{{ item.email }}</td>
					<td>
						<div v-for="role in item.roles" :class="['badge', 'badge-pill', 'mx-1', role === 'admin' ? 'badge-success' : 'badge-secondary']">{{ role }}</div>
					</td>
					<td>
						<div v-if="item.isActive" class="badge badge-pill badge-success">active</div>
						<div v-else class="badge badge-pill badge-danger">inactive</div>
					</td>
					<td class="text-right">
						<b-button :href="link('User:detail', {id: item.id})" variant="warning" size="sm" title="Edit">
							<b-icon icon="pencil"></b-icon>
						</b-button>
						<b-button @click="loginAs(item.id)" variant="primary" size="sm" v-b-tooltip.hover title="Log in as this user.">
							<b-icon icon="box-arrow-in-right"></b-icon>
						</b-button>
						<b-button @click="deleteUser(item.id)" variant="danger" size="sm" v-b-tooltip.hover title="Hide this user.">
							<b-icon icon="trash"></b-icon>
						</b-button>
					</td>
				</tr>
			</table>
			<b-pagination 
				v-model="paginator.page" 
				:per-page="paginator.itemsPerPage" 
				@change="sync()"
				:total-rows="paginator.itemCount" align="right" size="sm" class="mb-0">
			</b-pagination>
		</b-card>
	</div>`,
	data() {
		return {
			breadcrumb: [
				{
					label: 'Dashboard',
					href: '/'
				},
				{
					label: 'User Manager',
					href: '/user'
				}
			],
			buttons: [
				{
					variant: 'primary',
					label: 'Create',
					icon: 'fa-plus',
					action: 'modal',
					target: 'modal-user-create'
				}
			],
			paginator: {
				itemsPerPage: 0,
				page: 1,
				itemCount: 0,
			},
			search: {
				name: null,
				role: null,
				status: {
					label: null
				}
			},
			errors: {
				fullName: 'Full name format is invalid. Please include first name and last name.',
				email: 'E-mail format is invalid',
				isEmailValid: true
			},
			user: {
				form: {
					fullName: null,
					email: null,
					password: null,
					role: null
				},
				statusCount: null,
				items: [],
			},
			isCurrentUserUsing2fa: null,
			isUserCreating: false,
			roles: {},
			rolesSimple: {},
			isInputPasswordOpen: false
		}
	},
	mounted() {
		this.sync();
	},
	methods: {
		loginAs(id) {
			axiosApi.get(`user/login-as?id=${id}`)
				.then(req => {
					window.location.href = req.data.data.redirectUrl;
				})
		},
		checkMailDuplication() {
			axiosApi.get(`user/validate-user?email=${this.user.form.email}`)
				.then(req => {
					if (req.data.exist === true) {
						this.errors.email = 'This email is already in use';
						this.errors.isEmailValid = false;
					} else if (!this.user.form.email.match(/^([a-zA-Z0-9_\-\.]+)@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.)|(([a-zA-Z0-9\-]+\.)+))([a-zA-Z]{2,4}|[0-9]{1,3})(\]?)$/)) {
						this.errors.email = 'Please type valid e-mail adress';
						this.errors.isEmailValid = false;
					} else {
						this.errors.email = null;
						this.errors.isEmailValid = true;
					}
				})
		},
		processSearch(query) {
			this.search.name = query;
			this.sync();
		},
		sync() {
			this.$nextTick(() => {
				let query = {
					query: this.search.name === "" ? null : this.search.name,
					role: this.search.role === "" || this.search.role === "null" ? null : this.search.role,
					active: (this.search.status.label === "" || this.search.status.label === "null") ? null : this.search.status.label,
					page: this.paginator.page
				};

				axiosApi.get('user?' + httpBuildQuery(query)).then(req => {
					let data = req.data;
					this.user.items = data.list;
					this.roles = {null: 'All roles', ...data.roles};
					this.user.statusCount = data.statusCount;
					this.rolesSimple = data.roles;
					this.paginator = data.paginator;
					this.user.form.role = this.rolesSimple[0].value;
					this.isCurrentUserUsing2fa = data.isCurrentUserUsing2fa;
				})
			})
		},
		createUser() {
			let valid = this.$refs.form.checkValidity();
			if (!valid) this.$refs.form.classList.add('was-validated');

			if (valid) {
				this.isUserCreating = true;
				axiosApi.post('user', this.user.form).then((req) => {
					this.$bvModal.hide('modal-user-create');
					this.sync();
				}).finally(() => this.isUserCreating = false)
			}
		},
		deleteUser(id) {
			if (confirm('Really delete this user?')) {
				return axiosApi.delete(`/user?id=${id}`).then(req => {
					this.sync();
				});
			}
		}
	}
});