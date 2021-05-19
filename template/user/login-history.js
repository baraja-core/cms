Vue.component('user-login-history', {
	props: ['id'],
	template: `<b-card>
		<div v-if="items === null" class="text-center my-5">
			<b-spinner></b-spinner>
		</div>
		<template v-else>
			<div v-if="permitted === false" class="text-center my-5">
				You are not allowed to access this data.
				For more information, please contact the administrator.
			</div>
			<template v-else>
				<div v-if="items.length === 0">
					<p class="mb-0"><i>History is empty.</i></p>
				</div>
				<template v-else>
					<table class="table table-sm cms-table-no-border-top">
						<tr>
							<th>IP address</th>
							<th>Hostname</th>
							<th>User&nbsp;Agent</th>
							<th>Login&nbsp;time</th>
						</tr>
						<tr v-for="item in items">
							<td><code>{{ item.ip }}</code></td>
							<td><code>{{ item.hostname }}</code></td>
							<td>{{ item.userAgentString }}</td>
							<td>{{ item.loginDatetime }}</td>
						</tr>
					</table>
				</template>
			</template>
		</template>
	</b-card>`,
	data() {
		return {
			permitted: null,
			items: null
		}
	},
	mounted() {
		axiosApi.get(`user/login-history?id=${this.id}`).then(req => {
			let data = req.data;
			this.permitted = data.permitted;
			this.items = data.items;
			this.items.forEach(item => {
				item.userAgentString = data.userAgents[item.userAgent];
			})
		})
	}
});
