Vue.component('common-settings', {
	template: `<cms-default :card="true" title="Common settings">
	<div v-if="loading.init" class="text-center my-5">
		<b-spinner></b-spinner>
	</div>
	<template v-else>
		<b-alert :show="isOk" variant="success">
			<h4 class="alert-heading">Well done!</h4>
			<p>
				Your general configuration is set up correctly and all services work.
				Always be sure to check this message when making any configuration changes.
			</p>
		</b-alert>
		<b-alert :show="!isOk" variant="danger">
			<h4 class="alert-heading">Your basic configuration is corrupted!</h4>
			<p>
				Please check all form fields in detail to verify
				that they are filled in correctly and that communication to other services is working.
			</p>
			<p>
				A broker project configuration can cause severe malfunction
				of some critical parts of the application or a complete site downtime.
			</p>
		</b-alert>
		<div class="row">
			<div class="col-sm-6">
				<b-form-group label="This project name">
					<b-form-input v-model="projectName"></b-form-input>
				</b-form-group>
			</div>
			<div class="col-sm-6">
				<p>
					The name of the project is a publicly visible name,
					which we send in the header of all notification e-mails and
					is displayed in technical places.
				</p>
			</div>
		</div>
		<div class="row">
			<div class="col-sm-6">
				<b-form-group label="Main admin e-mail">
					<b-form-input v-model="adminEmail"></b-form-input>
				</b-form-group>
			</div>
			<div class="col-sm-6">
				<p>
					We will send all important technical reports and error information to this email.
					The email address remains hidden from other users and can only be seen by administrators.
					The owner of this e-mail box has the right to reset the master password for administration.
				</p>
			</div>
		</div>
		<div class="row">
			<div class="col-sm-6">
				<b-form-group label="Cloud token">
					<b-form-input v-model="cloudToken"></b-form-input>
				</b-form-group>
			</div>
			<div class="col-sm-6">
				<p>
					Baraja Cloud is an Internet service that helps manage publicly available information about
					your site, such as the technical status of individual URLs,
					outages, and other technical errors.
					The cloud also distributes internal e-mail notifications to your users.
				</p>
			</div>
		</div>
		<div class="row">
			<div class="col-sm-8">
				<h3>Locales</h3>
				<table class="table table-sm">
					<tr>
						<th>Locale</th>
						<th>Active</th>
						<th>Default</th>
						<th>Position</th>
					</tr>
					<tr v-for="locale in locales">
						<td>{{ locale.locale }}</td>
						<td>{{ locale.active }}</td>
						<td>{{ locale.default }}</td>
						<td>{{ locale.position }}</td>
					</tr>
				</table>
			</div>
			<div class="col">
				<p>
					Table of available languages for this project. Languages can only be added.
				</p>
			</div>
		</div>
		<div class="row">
			<div class="col-sm-8">
				<h3>Domains</h3>
				<table class="table table-sm">
					<tr>
						<th>HTTPS</th>
						<th>Domain</th>
						<th>WWW</th>
						<th>Locale</th>
						<th>Environment</th>
						<th>Default</th>
						<th>Protected</th>
					</tr>
					<tr v-for="domain in domains">
						<td>{{ domain.https }}</td>
						<td><code>{{ domain.domain }}</code></td>
						<td>{{ domain.www }}</td>
						<td>{{ domain.locale.locale }}</td>
						<td>{{ domain.environment }}</td>
						<td>{{ domain.default }}</td>
						<td>{{ domain.protected }}</td>
					</tr>
				</table>
			</div>
			<div class="col">
				<p>
					Table of all domains where this project is available.
					Some domains may be marked as BETA (test environment), which you can password protect.
				</p>
			</div>
		</div>
		<div class="row">
			<div class="col text-right">
				<b-button variant="primary" @click="save()">
					<template v-if="loading.saving"><b-spinner></b-spinner></template>
					<template v-else>Save all</template>
				</b-button>
			</div>
		</div>
	</template>
</cms-default>`,
	data() {
		return {
			loading: {
				init: true,
				saving: false
			},
			isOk: null,
			projectName: null,
			adminEmail: null,
			cloudToken: null,
			locales: null,
			domains: null
		}
	},
	mounted() {
		this.sync();
	},
	methods: {
		sync() {
			axiosApi.get(`cms-settings/common`).then(req => {
				this.loading.init = false;
				this.isOk = req.data.isOk;
				this.projectName = req.data.projectName;
				this.adminEmail = req.data.adminEmail;
				this.cloudToken = req.data.cloudToken;
				this.locales = req.data.locales;
				this.domains = req.data.domains;
			});
		},
		save() {
			this.saving = true;
			axiosApi.post('cms-settings/save-common', {
				projectName: this.projectName,
				adminEmail: this.adminEmail,
				cloudToken: this.cloudToken
			}).then(req => {
				this.sync();
			}).finally(() => this.loading.saving = false)
		}
	}
});
