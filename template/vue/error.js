Vue.component('cms-error', {
	props: ['path'],
	template: `<div>
		<b-alert show variant="warning" class="my-3">
			<b-row>
				<b-col cols="1" class="text-center">
					<b-icon icon="emoji-expressionless" font-scale="4"></b-icon>
				</b-col>
				<b-col>
					<h1 class="h4">Plugin does not exist</h1>
					<p>The requested URL <b>/{{ path }}</b> was not found on this administration.</p>
					<p>That’s all we know.</p>
					<b-btn :href="basePath + '/admin'" variant="primary" class="btn-sm">Dashboard</b-btn>
					<b-btn @click="eventBus.$emit('open-support')" variant="secondary" class="btn-sm">Open support</b-btn>
				</b-col>
			</b-row>
		</b-alert>
	</div>`
});
