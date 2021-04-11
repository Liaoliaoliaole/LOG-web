import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders, HttpParams } from '@angular/common/http';
import { Logstat } from '../../models/can-model';
import { Observable, of } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { environment } from 'src/environments/environment';
import { CanBusFlatData } from '../../device-info-table/device-info-table.component';
import { IsoStandard } from '../../models/iso-standard-model';
import { OpcUaConfigModel } from '../../models/opcua-config-model';
import { ToastrService } from 'ngx-toastr';
declare const compress: any;

@Injectable({
  providedIn: 'root'
})
export class CanbusService {
  constructor(private readonly http: HttpClient, private readonly toastr: ToastrService) { }

  getLogStatData(): Observable<Logstat[]> {
    const url = environment.API_ROOT + '?COMMAND=logstats';
    return this.http.get<Logstat[]>(url);
  }
  /*
  getIsoCodesByUnit(unit: string): Observable<IsoStandard[]> {
		const url = environment.API_ROOT + '?COMMAND=get_iso_codes_by_unit';
		let params = new HttpParams();
		if (unit && unit !== '-') {
		  params = new HttpParams().set('unit', unit);
		}
		return this.http.get<IsoStandard[]>(url, { params });
  }
  */

  getOpcUaConfigs(): Observable<OpcUaConfigModel[]> {
    const url = environment.API_ROOT + '?COMMAND=opcua_config';
    const result = this.http.get<any>(url).pipe(
      catchError(this.handleCanbusError)
    );
    return result;
  }

  saveOpcUaConfigs(canbusData: CanBusFlatData[]): Observable<void> {
	function ISOChannels()
	{
		this.data = new Array();
	}
	function ISOChannel_entry(ISOChannel,
							  IF_type,
							  Anchor,
							  Description,
							  Max,
							  Min,
							  Unit)
	{
		this.ISOChannel = ISOChannel;
		this.IF_type = IF_type;
		this.Anchor = Anchor;
		this.Description = Description === undefined || !Description.length ? "-" : Description;
		this.Max = isNaN(Max)? 0 : Max;
		this.Min = isNaN(Min)? 0 : Min;
		if(IF_type !== "SDAQ")
			this.Unit = Unit === undefined || Unit === null ? "-" : Unit;
	}
	const url = environment.API_ROOT;
    const headers = new HttpHeaders({
      'Content-Type': 'text/plain; charset=UTF-8'
    });
	var channels = new ISOChannels();
	for(let i = 0; i < canbusData.length; i++)
	{
		if(typeof canbusData[i].isoCode !== 'undefined' &&
		   typeof canbusData[i].type !== 'undefined' &&
		   typeof canbusData[i].anchor !== 'undefined')
		{
			channels.data.push(new ISOChannel_entry(canbusData[i].isoCode,
													canbusData[i].type,
													canbusData[i].anchor,
													canbusData[i].description,
													canbusData[i].maxValue,
													canbusData[i].minValue,
													canbusData[i].channelUnit));
		}
	}
    return this.http.post<void>(url, compress(JSON.stringify(channels)), {headers});
  }

  private handleCanbusError = (error) => {
    console.error(error);
    this.toastr.error(error.message, 'Error fetching logstat data', { disableTimeOut: true });
    return of(null);
  }
}
