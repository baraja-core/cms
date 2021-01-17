Vue.component('user-permissions', {
	props: ['id'],
	template: `<b-card>
			<div v-if="items === null" class="text-center my-5">
				<b-spinner></b-spinner>
			</div>
			<template v-else>
				<b-alert :show="isAdmin === false">
					<p>
						<b>This user is not administrator.</b>
						When you set up a user as an administrator, he gains access to the administration.
					</p>
					<b-button variant="primary" size="sm" v-b-modal.modal-set-admin>Set as admin</b-button>
				</b-alert>
				<b-alert :show="isAdmin === true" variant="success">
					<b>This user is administrator.</b>
				</b-alert>
				<label for="tags-basic">Roles</label>
				<b-form-tags v-model="roles" separator=" ,;" tag-variant="primary" tag-pills placeholder="Add new role..."></b-form-tags>
				<h3 class="mt-3">Plugins</h3>
				<table class="table table-sm">
					<tr v-for="plugin in items">
						<td style="width:32px">
							<b-form-checkbox v-model="plugin.active" :id="'permission-plugin-' + plugin.name" :value="true" :unchecked-value="false"></b-form-checkbox>
						</td>
						<td>
							<span v-b-tooltip.hover :title="'Type: ' + plugin.type">
								<b-icon icon="question-circle-fill" variant="info"></b-icon>	
							</span>
							<label :for="'permission-plugin-' + plugin.name" class="mb-0">
								<span class="pointer">{{ plugin.realName }}</span>
							</label>
							<div v-if="plugin.active === true">
								<code>{{ plugin.name }}</code>
							</div>
						</td>
						<td>
							<table v-if="plugin.active === true" class="table table-sm">
								<tr v-for="component in plugin.components">
									<td style="width:32px">
										<b-form-checkbox v-model="component.active" :id="'permission-component-' + component.name" :value="true" :unchecked-value="false"></b-form-checkbox>
									</td>
									<td>
										<span v-b-tooltip.hover :title="component.name">
											<b-icon icon="question-circle-fill" variant="info"></b-icon>	
										</span>
										<label :for="'permission-component-' + component.name" class="mb-0">
											<span class="pointer">{{ component.tab }}</span>
										</label>
									</td>
								</tr>
							</table>
						</td>
					</tr>
				</table>
				<b-button variant="primary" @click="saveAll" class="mt-3">Save all</b-button>
			</template>
			<b-modal id="modal-set-admin" title="Set user as admin" hide-footer>
				<p>
					If you set the user as an administrator,
					he will gain access to the administration
					and modules to which you will grant him access.
					You can cancel this operation at any time.
				</p>
				<b-form autocomplete="off" ref="setAdminForm">
					<b-form-group label="Enter your password to confirm:">
						<b-input-group>
							<b-form-input v-model="userPassword" type="password" required></b-form-input>
						</b-input-group>
					</b-form-group>
					<b-button variant="primary" @click="setAsAdmin">Set as admin</b-button>
				</b-form>
			</b-modal>
		</b-card>`,
	data() {
		return {
			items: null,
			roles: [],
			isAdmin: null,
			userPassword: ''
		}
	},
	mounted() {
		this.sync();
	},
	methods: {
		sync() {
			axiosApi.get(`user/permissions?id=${this.id}`).then(req => {
				let data = req.data;
				this.items = data.items;
				this.roles = data.roles;
				this.isAdmin = data.isAdmin;
			})
		},
		setAsAdmin() {
			axiosApi.post('user/mark-user-as-admin', {
				id: this.id,
				password: this.userPassword,
			}).then(req => {
				this.sync();
				this.$bvModal.hide('modal-set-admin');
			});
		},
		saveAll() {
			axiosApi.post('user/save-permissions', {
				id: this.id,
				roles: this.roles,
				permissions: this.items,
			}).then(req => {
				this.sync();
			});
		}
	}
});
