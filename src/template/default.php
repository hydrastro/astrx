<h1>AstrX</h1>

{{>*content}}

<pre>
Page title: {{title}}
Page description: {{description}}
Page keywords: {{keywords}}

Index: {{index}}
Follow: {{follow}}

rendered in {{time}}.

{{#navbar}}
    name: {{name}}
    url: {{url}}
    highlight: {{#highlight}}Y{{/highlight}}{{^highlight}}N{{/highlight}}
{{/navbar}}
</pre>
